<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAiDiscovery\Service\LlmsTxtConfig;
use Ruhrcoder\RcAiDiscovery\Service\LlmsTxtGenerator;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlCollection;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Prüft den Markdown-Text der llms.txt, den der Generator aus Kategorien, Konfiguration und
 * Admin-Feldern baut. Bricht er, steht in der Datei ein toter Verweis, ein leerer Abschnitt in der
 * Zweitsprache oder eine zerbrochene Linkstruktur, und KI-Systeme lesen genau diese Datei als
 * Wegweiser durch den Shop.
 */
final class LlmsTxtGeneratorTest extends TestCase
{
    private const NAV_ID = 'nav-category-id';

    private const SERVICE_ID = 'service-category-id';

    public function testGeneratesTitleSummarySectionsAndSitemap(): void
    {
        $content = $this->createGenerator('Mein Testshop')
            ->generate($this->createContext(), 'https://shop.example/', false, LlmsTxtConfig::auto());

        self::assertStringContainsString('# Mein Testshop', $content);
        self::assertStringContainsString('> Willkommen im Testshop.', $content);

        self::assertStringContainsString('## Kategorien', $content);
        self::assertStringContainsString('- [Damen](URL::cat-a)', $content);
        self::assertStringContainsString('- [Herren](URL::cat-b)', $content);

        self::assertStringContainsString('## Wichtige Seiten', $content);
        self::assertStringContainsString('- [Kontakt](URL::page-a)', $content);

        self::assertStringContainsString('## Sitemap', $content);
        self::assertStringContainsString('- https://shop.example/sitemap.xml', $content);
    }

    public function testShortVariantOmitsCategoryDescriptions(): void
    {
        $content = $this->createGenerator('Mein Testshop')
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        // Der Zeilenumbruch direkt hinter der Klammer schließt auch ein angehängtes „: …" aus.
        self::assertStringContainsString('- [Damen](URL::cat-a)' . "\n", $content);
        self::assertStringNotContainsString('Modische Damenkollektion', $content);
    }

    public function testFullVariantAddsCategoryDescriptions(): void
    {
        $content = $this->createGenerator('Mein Testshop')
            ->generate($this->createContext(), 'https://shop.example', true, LlmsTxtConfig::auto());

        self::assertStringContainsString('- [Damen](URL::cat-a): Modische Damenkollektion', $content);
    }

    public function testFallsBackToSalesChannelNameWhenShopNameEmpty(): void
    {
        $content = $this->createGenerator('')
            ->generate($this->createContext('Fallback-Kanal'), 'https://shop.example', false, LlmsTxtConfig::auto());

        self::assertStringContainsString('# Fallback-Kanal', $content);
    }

    public function testEmptyStorefrontUrlProducesNoSitemapSection(): void
    {
        $content = $this->createGenerator('Mein Testshop')
            ->generate($this->createContext(), '', false, LlmsTxtConfig::auto());

        self::assertStringNotContainsString('## Sitemap', $content);
    }

    public function testFullVariantTruncatesLongDescriptionWithEllipsis(): void
    {
        // 300 Zeichen liegen deutlich über der Obergrenze von 240 aus `DESCRIPTION_MAX_LENGTH`.
        $long = str_repeat('a', 300);
        $content = $this->generatorWithTopLevel([$this->category('cat-x', 'Lang', $long)])
            ->generate($this->createContext(), 'https://shop.example', true, LlmsTxtConfig::auto());

        self::assertStringContainsString('…', $content);
        self::assertStringNotContainsString($long, $content);
    }

    public function testSkipsCategoryWithBlankName(): void
    {
        $content = $this->generatorWithTopLevel([$this->category('cat-blank', '   ', null)])
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        self::assertStringNotContainsString('URL::cat-blank', $content);
    }

    /**
     * Ohne kanonische Adresse bliebe nur die Kennungsadresse `/navigation/<id>` stehen. Gemessen am
     * Live-Shop (26.08.2026) antwortet sie für eine solche Kategorie mit 404; lieber ein Eintrag
     * weniger als ein toter.
     */
    public function testSkipsCategoryWithoutCanonicalSeoUrl(): void
    {
        $content = $this->generatorWithTopLevel([$this->categoryWithoutSeoUrl('cat-ohne', 'Beliebte Kategorien', null)])
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        self::assertStringNotContainsString('URL::cat-ohne', $content);
        self::assertStringNotContainsString('Beliebte Kategorien', $content);
    }

    /**
     * Eine ausgelassene Kategorie darf nicht still verschwinden. Auf einem produktiven Shop steht die
     * Protokollstufe auf `error`, ein `info` ginge dort wortlos unter; deshalb zählt die Stufe mit.
     */
    public function testSkippedCategoryIsReportedAsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('ohne kanonische Adresse'),
                self::callback(static fn (array $kontext): bool => ($kontext['categoryId'] ?? null) === 'cat-still')
            );

        $this->generatorWithTopLevel([$this->categoryWithoutSeoUrl('cat-still', 'Ohne Adresse', null)], $logger)
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());
    }

    /**
     * Gegenprobe zum Auslassen: Kategorien mit kanonischer Adresse bleiben in der Datei. Ohne sie
     * fiele ein Filter, der alles verwirft, in keinem Test auf.
     */
    public function testKeepsCategoryWithCanonicalSeoUrl(): void
    {
        $content = $this->generatorWithTopLevel([$this->category('cat-mit', 'Handläufe', null)])
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        self::assertStringContainsString('- [Handläufe](URL::cat-mit)', $content);
    }

    public function testEscapesMarkdownBracketsInCategoryName(): void
    {
        $content = $this->generatorWithTopLevel([$this->category('cat-b', 'Schuhe [neu]', null)])
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        self::assertStringContainsString('- [Schuhe \[neu\]](URL::cat-b)', $content);
    }

    public function testTitleOverrideReplacesShopName(): void
    {
        $content = $this->createGenerator('Mein Testshop')->generate(
            $this->createContext(),
            'https://shop.example',
            false,
            new LlmsTxtConfig('Ruhrcoder Fachhandel', null, null)
        );

        self::assertStringContainsString('# Ruhrcoder Fachhandel', $content);
        self::assertStringNotContainsString('# Mein Testshop', $content);
    }

    public function testSummaryOverrideReplacesAutoSummary(): void
    {
        $content = $this->createGenerator('Mein Testshop')->generate(
            $this->createContext(),
            'https://shop.example',
            false,
            new LlmsTxtConfig(null, 'Edelstahl-Fertigung auf Maß für DACH.', null)
        );

        self::assertStringContainsString('> Edelstahl-Fertigung auf Maß für DACH.', $content);
        self::assertStringNotContainsString('Willkommen im Testshop.', $content);
    }

    public function testAdditionalContentIsInsertedBeforeSitemap(): void
    {
        $content = $this->createGenerator('Mein Testshop')->generate(
            $this->createContext(),
            'https://shop.example',
            false,
            new LlmsTxtConfig(null, null, "## Kontakt\n\n- [Hilfe](/hilfe)")
        );

        self::assertStringContainsString("## Kontakt\n\n- [Hilfe](/hilfe)", $content);

        $additionalPosition = mb_strpos($content, '## Kontakt');
        $sitemapPosition = mb_strpos($content, '## Sitemap');
        self::assertIsInt($additionalPosition);
        self::assertIsInt($sitemapPosition);
        self::assertLessThan($sitemapPosition, $additionalPosition);
    }

    /**
     * Ohne gepflegte Admin-Felder ist die Ausgabe zeichengenau die automatische. Der Vergleich des
     * ganzen Textes fängt auch eine verirrte Leerzeile, die ein ungesetztes Feld hinterlassen könnte.
     */
    public function testAutoConfigProducesUnchangedOutput(): void
    {
        $content = $this->createGenerator('Mein Testshop')
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        $expected = <<<'TXT'
            # Mein Testshop

            > Willkommen im Testshop.

            ## Kategorien

            - [Damen](URL::cat-a)
            - [Herren](URL::cat-b)

            ## Wichtige Seiten

            - [Kontakt](URL::page-a)

            ## Sitemap

            - https://shop.example/sitemap.xml

            TXT;

        self::assertSame($expected, $content);
    }

    /**
     * Fehlt die Übersetzung in der aufgerufenen Sprache, zeigt die Storefront den Wert aus der
     * Rückfallkette — die Datei muss dasselbe tun, sonst ist sie in der Zweitsprache leer.
     */
    public function testUsesTranslationFallbackForCategoryName(): void
    {
        $category = $this->category('cat-f', null, null);
        $category->setTranslated(['name' => 'Grocery']);

        $content = $this->generatorWithTopLevel([$category])
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        self::assertStringContainsString('- [Grocery](URL::cat-f)', $content);
    }

    public function testLanguageSpecificNameWinsOverFallback(): void
    {
        $category = $this->category('cat-g', 'Lebensmittel', null);
        $category->setTranslated(['name' => 'Grocery']);

        $content = $this->generatorWithTopLevel([$category])
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        self::assertStringContainsString('- [Lebensmittel](URL::cat-g)', $content);
        self::assertStringNotContainsString('Grocery', $content);
    }

    public function testCategoryWithoutAnyNameIsStillSkipped(): void
    {
        $content = $this->generatorWithTopLevel([$this->category('cat-h', null, null)])
            ->generate($this->createContext(), 'https://shop.example', false, LlmsTxtConfig::auto());

        self::assertStringNotContainsString('URL::cat-h', $content);
    }

    public function testUsesTranslationFallbackForDescription(): void
    {
        $category = $this->category('cat-i', 'Grocery', null);
        $category->setTranslated(['metaDescription' => 'Alles fürs Kochen.']);

        $content = $this->generatorWithTopLevel([$category])
            ->generate($this->createContext(), 'https://shop.example', true, LlmsTxtConfig::auto());

        self::assertStringContainsString('- [Grocery](URL::cat-i): Alles fürs Kochen.', $content);
    }

    /**
     * Das Repository liefert nur die angegebenen Top-Level-Kategorien, also weder Kurzbeschreibung
     * noch Service-Seiten; so steht im Text nichts außer den Kategorien unter Test.
     *
     * @param list<CategoryEntity> $topLevel
     */
    private function generatorWithTopLevel(array $topLevel, ?LoggerInterface $logger = null): LlmsTxtGenerator
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')->willReturn('Mein Testshop');

        $seoReplacer = $this->createMock(SeoUrlPlaceholderHandlerInterface::class);
        $seoReplacer->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = []): string => 'URL::' . ($parameters['navigationId'] ?? '')
        );

        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->method('search')->willReturnCallback(
            function (Criteria $criteria) use ($topLevel): EntitySearchResult {
                if ($criteria->getIds() !== []) {
                    return $this->searchResult([]);
                }

                foreach ($criteria->getFilters() as $filter) {
                    if ($filter instanceof EqualsFilter && $filter->getField() === 'parentId' && $filter->getValue() === self::NAV_ID) {
                        return $this->searchResult($topLevel);
                    }
                }

                return $this->searchResult([]);
            }
        );

        return new LlmsTxtGenerator($systemConfig, $repository, $seoReplacer, $logger ?? new NullLogger());
    }

    private function createGenerator(string $configuredShopName): LlmsTxtGenerator
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')->willReturn($configuredShopName);

        $seoReplacer = $this->createMock(SeoUrlPlaceholderHandlerInterface::class);
        $seoReplacer->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = []): string => 'URL::' . ($parameters['navigationId'] ?? '')
        );

        return new LlmsTxtGenerator($systemConfig, $this->createCategoryRepository(), $seoReplacer, new NullLogger());
    }

    /**
     * @return SalesChannelRepository<CategoryCollection>
     */
    private function createCategoryRepository(): SalesChannelRepository
    {
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->method('search')->willReturnCallback(
            function (Criteria $criteria): EntitySearchResult {
                if ($criteria->getIds() !== []) {
                    return $this->searchResult([$this->category('nav-category-id', null, 'Willkommen im Testshop.')]);
                }

                foreach ($criteria->getFilters() as $filter) {
                    if ($filter instanceof EqualsFilter && $filter->getField() === 'parentId') {
                        return $filter->getValue() === self::NAV_ID
                            ? $this->searchResult([
                                $this->category('cat-a', 'Damen', 'Modische Damenkollektion'),
                                $this->category('cat-b', 'Herren', null),
                            ])
                            : $this->searchResult([$this->category('page-a', 'Kontakt', null)]);
                    }
                }

                return $this->searchResult([]);
            }
        );

        return $repository;
    }

    /**
     * @param list<CategoryEntity> $entities
     *
     * @return EntitySearchResult<CategoryCollection>
     */
    private function searchResult(array $entities): EntitySearchResult
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new CategoryCollection($entities));

        return $result;
    }

    /**
     * Eine Kategorie mit kanonischer Adresse, dem Regelfall im Shop. Den Gegenfall stellt
     * `categoryWithoutSeoUrl()` her.
     */
    private function category(string $id, ?string $name, ?string $description): CategoryEntity
    {
        $category = $this->categoryWithoutSeoUrl($id, $name, $description);

        $seoUrl = new SeoUrlEntity();
        $seoUrl->setId($id . '-seo');
        $seoUrl->setSeoPathInfo('kategorie/' . $id);
        $seoUrl->setIsCanonical(true);
        $seoUrl->setIsDeleted(false);
        $category->setSeoUrls(new SeoUrlCollection([$seoUrl]));

        return $category;
    }

    private function categoryWithoutSeoUrl(string $id, ?string $name, ?string $description): CategoryEntity
    {
        $category = new CategoryEntity();
        $category->setId($id);
        if ($name !== null) {
            $category->setName($name);
        }
        $category->setMetaDescription($description);

        return $category;
    }

    private function createContext(string $salesChannelName = 'Testshop'): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-id');
        $salesChannel->setName($salesChannelName);
        $salesChannel->setNavigationCategoryId(self::NAV_ID);
        $salesChannel->setServiceCategoryId(self::SERVICE_ID);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        return $context;
    }
}
