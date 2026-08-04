<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Baut den Inhalt der llms.txt im offiziellen Markdown-Format automatisch aus den Shop-Daten
 * der aufrufenden Sales-Channel-Domain. Kategorie-/Seiten-Links werden als SEO-Platzhalter
 * erzeugt; der Controller ersetzt sie anschließend domain-abhängig durch absolute URLs.
 *
 * Im Admin gepflegte Overrides kommen als `LlmsTxtConfig` herein und greifen jeweils vor dem
 * Auto-Wert; ist nichts gepflegt, ist die Ausgabe unverändert automatisch.
 */
final class LlmsTxtGenerator
{
    /**
     * Obergrenze pro Abschnitt — verhindert unbegrenzte Result-Sets bei sehr großen Menü-Bäumen.
     */
    private const CATEGORY_LIMIT = 100;

    /**
     * Maximale Länge einer Kurzbeschreibung, damit die Datei kompakt und maschinenfreundlich bleibt.
     */
    private const DESCRIPTION_MAX_LENGTH = 240;

    private const SHOP_NAME_CONFIG_KEY = 'core.basicInformation.shopName';

    private const DEFAULT_SHOP_NAME = 'Shop';

    /**
     * @param SalesChannelRepository<\Shopware\Core\Content\Category\CategoryCollection> $categoryRepository
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly SalesChannelRepository $categoryRepository,
        private readonly SeoUrlPlaceholderHandlerInterface $seoUrlReplacer,
    ) {
    }

    /**
     * Erzeugt den vollständigen llms.txt-Text (mit SEO-Platzhaltern für Links).
     *
     * @param string        $storefrontUrl absolute Basis-URL der Sales-Channel-Domain (für die Sitemap)
     * @param bool          $full          true = ausführliche Variante (mit Beschreibungen und Footer-Seiten)
     * @param LlmsTxtConfig $config        Admin-Overrides; nicht gesetzte Felder bleiben automatisch
     */
    public function generate(SalesChannelContext $context, string $storefrontUrl, bool $full, LlmsTxtConfig $config): string
    {
        $salesChannel = $context->getSalesChannel();

        $lines = ['# ' . ($config->title ?? $this->resolveShopName($context, $salesChannel))];

        // Ist die Beschreibung gepflegt, entfällt die Kategorie-Abfrage der Automatik.
        $summary = $config->summary ?? $this->resolveSummary($salesChannel->getNavigationCategoryId(), $context);
        if ($summary !== null) {
            $lines[] = '';
            $lines[] = '> ' . $summary;
        }

        $this->appendSection($lines, 'Kategorien', $this->buildCategoryEntries($salesChannel, $context, $full));
        $this->appendSection($lines, 'Wichtige Seiten', $this->buildPageEntries($salesChannel, $context, $full));

        if ($config->additionalContent !== null) {
            $lines[] = '';
            $lines[] = $config->additionalContent;
        }

        $sitemapUrl = $this->buildSitemapUrl($storefrontUrl);
        if ($sitemapUrl !== null) {
            $this->appendSection($lines, 'Sitemap', ['- ' . $sitemapUrl]);
        }

        return implode("\n", $lines) . "\n";
    }

    private function resolveShopName(SalesChannelContext $context, SalesChannelEntity $salesChannel): string
    {
        $configured = $this->systemConfigService->getString(self::SHOP_NAME_CONFIG_KEY, $context->getSalesChannelId());
        if (trim($configured) !== '') {
            return $this->singleLine($configured);
        }

        $name = $this->translated($salesChannel->getName(), $salesChannel->getTranslation('name'));

        return $this->singleLine($name ?? self::DEFAULT_SHOP_NAME);
    }

    /**
     * Liest ein übersetzbares Feld so, wie die Storefront es anzeigt: bevorzugt den Wert der
     * aufgerufenen Sprache, sonst den aus der Rückfallkette. Ohne diesen Rückfall bliebe die Datei
     * in einer Sprache leer, in der die Storefront sehr wohl Inhalte zeigt.
     */
    private function translated(?string $direct, mixed $fallback): ?string
    {
        if ($direct !== null && trim($direct) !== '') {
            return $direct;
        }

        return \is_string($fallback) && trim($fallback) !== '' ? $fallback : null;
    }

    private function resolveSummary(string $navigationCategoryId, SalesChannelContext $context): ?string
    {
        $category = $this->categoryRepository
            ->search(new Criteria([$navigationCategoryId]), $context)
            ->getEntities()
            ->first();

        if (!$category instanceof CategoryEntity) {
            return null;
        }

        return $this->categoryText($category);
    }

    /**
     * @return list<string>
     */
    private function buildCategoryEntries(SalesChannelEntity $salesChannel, SalesChannelContext $context, bool $full): array
    {
        return $this->entriesFromChildren([$salesChannel->getNavigationCategoryId()], $context, $full);
    }

    /**
     * Service- und (nur in der full-Variante) Footer-Kategorie liefern die „Wichtigen Seiten".
     *
     * @return list<string>
     */
    private function buildPageEntries(SalesChannelEntity $salesChannel, SalesChannelContext $context, bool $full): array
    {
        $parentIds = [];
        $serviceCategoryId = $salesChannel->getServiceCategoryId();
        if ($serviceCategoryId !== null) {
            $parentIds[] = $serviceCategoryId;
        }

        if ($full) {
            $footerCategoryId = $salesChannel->getFooterCategoryId();
            if ($footerCategoryId !== null && !\in_array($footerCategoryId, $parentIds, true)) {
                $parentIds[] = $footerCategoryId;
            }
        }

        return $this->entriesFromChildren($parentIds, $context, $full);
    }

    /**
     * @param list<string> $parentIds
     *
     * @return list<string>
     */
    private function entriesFromChildren(array $parentIds, SalesChannelContext $context, bool $full): array
    {
        $entries = [];
        $seen = [];

        foreach ($parentIds as $parentId) {
            foreach ($this->loadActiveChildren($parentId, $context) as $category) {
                $id = $category->getId();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $entry = $this->formatCategoryLink($category, $full);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @return iterable<CategoryEntity>
     */
    private function loadActiveChildren(string $parentId, SalesChannelContext $context): iterable
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->setLimit(self::CATEGORY_LIMIT);

        return $this->categoryRepository->search($criteria, $context)->getEntities();
    }

    private function formatCategoryLink(CategoryEntity $category, bool $full): ?string
    {
        $name = $this->translated($category->getName(), $category->getTranslation('name'));
        if ($name === null) {
            return null;
        }

        $url = $this->seoUrlReplacer->generate('frontend.navigation.page', ['navigationId' => $category->getId()]);
        $line = '- [' . $this->escapeLinkText($name) . '](' . $url . ')';

        if ($full) {
            $description = $this->categoryText($category);
            if ($description !== null) {
                $line .= ': ' . $description;
            }
        }

        return $line;
    }

    /**
     * Kurzbeschreibung einer Kategorie (Meta-Description bevorzugt, sonst Beschreibung), bereinigt.
     */
    private function categoryText(CategoryEntity $category): ?string
    {
        $metaDescription = $this->translated($category->getMetaDescription(), $category->getTranslation('metaDescription'));
        $description = $this->translated($category->getDescription(), $category->getTranslation('description'));

        return $this->normalizeDescription($metaDescription ?? $description);
    }

    private function buildSitemapUrl(string $storefrontUrl): ?string
    {
        $base = rtrim(trim($storefrontUrl), '/');
        if ($base === '') {
            return null;
        }

        return $base . '/sitemap.xml';
    }

    /**
     * @param list<string> $lines
     * @param list<string> $entries
     */
    private function appendSection(array &$lines, string $heading, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '## ' . $heading;
        $lines[] = '';
        foreach ($entries as $entry) {
            $lines[] = $entry;
        }
    }

    /**
     * Entfernt HTML und komprimiert Whitespace; kürzt auf eine maschinenfreundliche Länge.
     */
    private function normalizeDescription(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH)) . '…';
        }

        return $text;
    }

    /**
     * Verhindert, dass Zeilenumbrüche in Namen die Markdown-Struktur zerstören.
     */
    private function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Maskiert Markdown-Linktext, damit eckige Klammern im Namen die `[Text](URL)`-Struktur nicht
     * aufbrechen können (Schutz gegen versehentliche/böswillige Link-Injection).
     */
    private function escapeLinkText(string $value): string
    {
        return str_replace(['[', ']'], ['\[', '\]'], $this->singleLine($value));
    }
}
