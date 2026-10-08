<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Baut den Text einer llms-Datei im Markdown-Format von llmstxt.org aus den Shop-Daten des
 * übergebenen Kontexts: Titel, Kurzbeschreibung, Kategorien, wichtige Seiten, Zusatz-Inhalt und
 * Sitemap, in dieser Reihenfolge.
 *
 * Links entstehen als SEO-Platzhalter. `LlmsDocumentGenerator` ersetzt sie danach mit der Domain des
 * Dokuments durch absolute Adressen; dieselbe Kategorie bekommt so je Sprache ihre eigene Adresse.
 * Vorgaben aus `LlmsTxtConfig` gehen dem automatisch ermittelten Wert vor.
 */
final class LlmsTxtGenerator
{
    /**
     * Obergrenze je Elternkategorie, nicht je Abschnitt: „Wichtige Seiten" fragt Service- und
     * Footer-Kategorie einzeln ab. Ein Menü mit mehr als 100 Einträgen auf einer Ebene ist kein
     * Wegweiser mehr, und die Grenze hält die Abfrage bei ausufernden Bäumen klein. Was darüber
     * hinausgeht, fällt nach Namen sortiert hinten weg.
     */
    private const CATEGORY_LIMIT = 100;

    /**
     * Fasst eine übliche Meta-Description von 150 bis 160 Zeichen vollständig und kürzt nur lange
     * Beschreibungstexte, damit jeder Eintrag eine überschaubare Zeile bleibt.
     */
    private const DESCRIPTION_MAX_LENGTH = 240;

    private const SHOP_NAME_CONFIG_KEY = 'core.basicInformation.shopName';

    /**
     * Letzter Rückfall, wenn weder Shopname noch Kanalname gepflegt sind; die Datei braucht eine
     * Überschrift.
     */
    private const DEFAULT_SHOP_NAME = 'Shop';

    /**
     * @param SalesChannelRepository<\Shopware\Core\Content\Category\CategoryCollection> $categoryRepository
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly SalesChannelRepository $categoryRepository,
        private readonly SeoUrlPlaceholderHandlerInterface $seoUrlReplacer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Erzeugt den vollständigen Text mit SEO-Platzhaltern an den Links, abgeschlossen mit einem
     * Zeilenumbruch.
     *
     * @param string        $storefrontUrl absolute Basisadresse der Domain, nur für den Sitemap-Link
     * @param bool          $full          true = Langfassung mit Beschreibungen und Footer-Seiten
     * @param LlmsTxtConfig $config        Vorgaben der Plugin-Konfiguration; `null`-Felder werden ermittelt
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

        // Der Zusatz-Inhalt kommt unverändert herein und bringt seine Überschriften selbst mit.
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

    /**
     * Shopname aus den Grundeinstellungen, sonst der übersetzte Name des Verkaufskanals, sonst
     * `DEFAULT_SHOP_NAME`.
     */
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

    /**
     * Die Kurzbeschreibung kommt aus der Einstiegskategorie des Hauptmenüs, Meta-Description vor
     * Beschreibung. `null` lässt die Zitatzeile ganz weg.
     */
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
     * Nur die oberste Menüebene: Sie beschreibt das Sortiment, die tieferen Ebenen führt die Sitemap
     * am Ende der Datei.
     *
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

        // Die Footer-Kategorie nur in der Langfassung. Ist sie dieselbe wie die Service-Kategorie,
        // würde sie sonst ein zweites Mal abgefragt.
        if ($full) {
            $footerCategoryId = $salesChannel->getFooterCategoryId();
            if ($footerCategoryId !== null && !\in_array($footerCategoryId, $parentIds, true)) {
                $parentIds[] = $footerCategoryId;
            }
        }

        return $this->entriesFromChildren($parentIds, $context, $full);
    }

    /**
     * Die direkten Kinder aller Elternkategorien als Listeneinträge, je Elternkategorie in der
     * Sortierung von `loadActiveChildren()`. Eine Kategorie erscheint höchstens einmal.
     *
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

        // Unsichtbare Kategorien zeigt die Storefront nicht; in einer Datei, die Maschinen als
        // Wegweiser lesen, haben sie erst recht nichts verloren.
        $criteria->addFilter(new EqualsFilter('visible', true));

        // `link` verweist woanders hin, `folder` ist eine reine Sortiergruppe — beide haben keine
        // eigene Seite. Ein Eintrag darauf ist bestenfalls eine Umleitung, schlimmstenfalls ein
        // toter Verweis.
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsAnyFilter('type', [CategoryDefinition::TYPE_LINK, CategoryDefinition::TYPE_FOLDER]),
        ]));

        // Die kanonische Adresse wird mitgeladen, weil ohne sie kein brauchbarer Verweis entsteht;
        // die Begründung steht bei `formatCategoryLink()`. Der Filter fragt nicht nach der Sprache,
        // eine Adresse in irgendeiner Sprache des Kanals genügt ihm.
        $criteria->addAssociation('seoUrls');
        $seoCriteria = $criteria->getAssociation('seoUrls');
        $seoCriteria->addFilter(new EqualsFilter('isCanonical', true));
        $seoCriteria->addFilter(new EqualsFilter('isDeleted', false));
        $seoCriteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannelId()));

        // Alphabetisch statt in Menüreihenfolge: Die steckt in der Verkettung über `afterCategoryId`
        // und lässt sich nicht als Sortierung abfragen.
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->setLimit(self::CATEGORY_LIMIT);

        return $this->categoryRepository->search($criteria, $context)->getEntities();
    }

    /**
     * Baut den Listeneintrag einer Kategorie oder gibt `null` zurück, wenn sie keinen Namen oder
     * keine kanonische Adresse hat.
     *
     * Der Platzhalter wird später durch die SEO-Adresse ersetzt, wenn es eine gibt. Gibt es keine,
     * bleibt die Kennungsadresse `/navigation/<id>` stehen. Am Live-Shop antwortet diese Adresse mit
     * 404, und wer `navigation/` in seiner robots.txt sperrt, macht sie zusätzlich unerreichbar.
     *
     * Eine `llms.txt` ist eine Empfehlung an Maschinen. Ein toter Verweis darin beschädigt genau das
     * Vertrauen, das die Datei herstellen soll; lieber ein Eintrag weniger als ein falscher.
     */
    private function formatCategoryLink(CategoryEntity $category, bool $full): ?string
    {
        $name = $this->translated($category->getName(), $category->getTranslation('name'));
        if ($name === null) {
            return null;
        }

        $seoUrls = $category->getSeoUrls();
        if ($seoUrls === null || $seoUrls->count() === 0) {
            // Eine sichtbare Seite ohne eigene Adresse ist ein Pflegefehler, deshalb `warning`.
            // Laut wird es trotzdem nicht: Sortiergruppen und Verweis-Kategorien hat
            // `loadActiveChildren()` schon ausgefiltert, hier kommt nur der echte Sonderfall an.
            // Die Kern-Vorgabe für `prod` schreibt erst ab `error` ins Protokoll; wer die Meldung
            // dort sehen will, braucht für den Kanal `rc_ai_discovery` eine eigene Stufe.
            $this->logger->warning('rc-ai-discovery: Kategorie ohne kanonische Adresse in der llms.txt ausgelassen', [
                'categoryId' => $category->getId(),
                'name' => $name,
            ]);

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
     * Entfernt HTML, fasst Leerraum zu einzelnen Leerzeichen zusammen und kürzt auf
     * `DESCRIPTION_MAX_LENGTH` Zeichen plus Auslassungszeichen. `null` heißt: kein brauchbarer Text.
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
     * Maskiert eckige Klammern im Linktext. Ein Kategoriename wie `Angebote](https://…` könnte sonst
     * die `[Text](URL)`-Form aufbrechen und einen fremden Link in die Datei setzen.
     */
    private function escapeLinkText(string $value): string
    {
        return str_replace(['[', ']'], ['\[', '\]'], $this->singleLine($value));
    }
}
