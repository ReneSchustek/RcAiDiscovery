<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawlerCatalog;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiRulesConfigProvider;
use Ruhrcoder\RcAiDiscovery\Subscriber\RobotsAiRulesSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Robots\RobotsPage;
use Shopware\Storefront\Page\Robots\RobotsPageLoadedEvent;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirective;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirectiveType;
use Shopware\Storefront\Page\Robots\Struct\RobotsUserAgentBlock;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft, welche KI-Crawler-Blöcke das Plugin in die robots.txt schreibt. Ein Fehler hier sperrt im
 * schlimmsten Fall Suchcrawler aus, überschreibt eine im Shop gepflegte Regel oder schickt die
 * KI-Crawler als einzige in alle Filter- und Sortieradressen, weil ihr eigener Block den
 * Sammelblock samt dessen Schutzregeln ersetzt.
 */
final class RobotsAiRulesSubscriberTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    public function testWritesNothingWhileSwitchedOff(): void
    {
        $page = $this->page();

        $this->subscriber(['aiRulesEnabled' => false])->onRobotsPageLoaded($this->event($page));

        self::assertSame([], $page->getGlobalUserAgentBlocks());
    }

    /**
     * Die vier erwarteten Zeilen sind die Spiegelung aus `allowDirectives()`. Der Test liest die
     * Core-Vorlage nicht mit: deren `Allow: /*referringSalesChannel=` und `Allow: /thumbnail/*?ts=`
     * (Core 6.7.15.0) fehlen in der Spiegelung, ohne dass er anschlägt.
     */
    public function testAllowedGroupMirrorsCoreDefaultRules(): void
    {
        $page = $this->page();

        $this->subscriber($this->enabled())->onRobotsPageLoaded($this->event($page));

        $block = $this->blockFor($page, 'PerplexityBot');
        self::assertNotNull($block);
        self::assertSame(
            ['Allow: /', 'Disallow: /*?', 'Allow: /*theme/', 'Allow: /media/*?ts='],
            array_map(static fn (RobotsDirective $d): string => $d->render(), $block->directives),
            'Ein eigener Block ersetzt den Sammelblock — die Core-Schutzregeln müssen mitkommen.'
        );
    }

    public function testBlockedGroupWritesDisallowOnly(): void
    {
        $page = $this->page();

        $this->subscriber($this->enabled(['aiRulesTraining' => 'block']))->onRobotsPageLoaded($this->event($page));

        $block = $this->blockFor($page, 'GPTBot');
        self::assertNotNull($block);
        self::assertSame(['Disallow: /'], array_map(static fn (RobotsDirective $d): string => $d->render(), $block->directives));
    }

    public function testGroupsAreConfiguredIndependently(): void
    {
        $page = $this->page();

        $this->subscriber($this->enabled(['aiRulesTraining' => 'block']))->onRobotsPageLoaded($this->event($page));

        // Nur Training ist gesperrt; Suche und Abruf behalten ihre Freigabe.
        self::assertSame(['Disallow: /'], $this->rendered($page, 'ClaudeBot'));
        self::assertContains('Allow: /', $this->rendered($page, 'OAI-SearchBot'));
        self::assertContains('Allow: /', $this->rendered($page, 'ChatGPT-User'));
    }

    public function testExistingBlockIsNotDuplicatedOrOverwritten(): void
    {
        $existing = new RobotsUserAgentBlock('GPTBot', [new RobotsDirective(RobotsDirectiveType::DISALLOW, '/intern')]);
        $page = $this->page([$existing]);

        $this->subscriber($this->enabled())->onRobotsPageLoaded($this->event($page));

        $gptBlocks = array_filter(
            $page->getGlobalUserAgentBlocks(),
            static fn (RobotsUserAgentBlock $block): bool => $block->userAgent === 'GPTBot'
        );
        self::assertCount(1, $gptBlocks, 'Eine im Shop gepflegte Regel darf nicht dupliziert werden.');
        self::assertSame(['Disallow: /intern'], $this->rendered($page, 'GPTBot'));
    }

    public function testExistingBlockMatchIsCaseInsensitive(): void
    {
        // Crawler werten den User-agent ohne Groß- und Kleinschreibung aus; ein zweiter Block
        // „GPTBot" neben „gptbot" wäre für sie dieselbe Gruppe doppelt.
        $page = $this->page([new RobotsUserAgentBlock('gptbot', [new RobotsDirective(RobotsDirectiveType::DISALLOW, '/')])]);

        $this->subscriber($this->enabled())->onRobotsPageLoaded($this->event($page));

        self::assertCount(
            0,
            array_filter($page->getGlobalUserAgentBlocks(), static fn (RobotsUserAgentBlock $b): bool => $b->userAgent === 'GPTBot')
        );
    }

    public function testCheckOnlyCrawlersNeverGetARule(): void
    {
        $page = $this->page();

        $this->subscriber($this->enabled())->onRobotsPageLoaded($this->event($page));

        self::assertNull($this->blockFor($page, 'Bingbot'), 'Bingbot trägt auch die klassische Bing-Suche.');
        self::assertNull($this->blockFor($page, 'anthropic-ai'), 'Abgelöste Tokens bekommen keine neue Regel.');
    }

    public function testEveryWritableCatalogEntryGetsExactlyOneBlock(): void
    {
        $page = $this->page();
        $catalog = new AiCrawlerCatalog();

        $this->subscriber($this->enabled())->onRobotsPageLoaded($this->event($page));

        $expected = 0;
        foreach ([AiCrawlerCatalog::GROUP_SEARCH, AiCrawlerCatalog::GROUP_FETCH, AiCrawlerCatalog::GROUP_TRAINING] as $group) {
            $expected += \count($catalog->writableOfGroup($group));
        }

        self::assertCount($expected, $page->getGlobalUserAgentBlocks());
    }

    /**
     * Fehlende Schlüssel liefern `false` und `''`. Das schaltet die Regeln ab und gilt bei den
     * Gruppen als „erlauben", wie es der `AiRulesConfigProvider` für leere Werte festlegt.
     *
     * @param array<string, bool|string> $config
     */
    private function subscriber(array $config): RobotsAiRulesSubscriber
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')->willReturnCallback(
            static fn (string $key): bool => (bool) ($config[self::shortKey($key)] ?? false)
        );
        $systemConfig->method('getString')->willReturnCallback(
            static fn (string $key): string => (string) ($config[self::shortKey($key)] ?? '')
        );

        return new RobotsAiRulesSubscriber(new AiCrawlerCatalog(), new AiRulesConfigProvider($systemConfig));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, bool|string>
     */
    private function enabled(array $overrides = []): array
    {
        return array_merge([
            'aiRulesEnabled' => true,
            'aiRulesSearch' => 'allow',
            'aiRulesFetch' => 'allow',
            'aiRulesTraining' => 'allow',
        ], $overrides);
    }

    private static function shortKey(string $configKey): string
    {
        $parts = explode('.', $configKey);

        return end($parts);
    }

    /**
     * @param list<RobotsUserAgentBlock> $blocks
     */
    private function page(array $blocks = []): RobotsPage
    {
        $page = new RobotsPage();
        $page->setGlobalUserAgentBlocks($blocks);

        return $page;
    }

    private function event(RobotsPage $page): RobotsPageLoadedEvent
    {
        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, self::SALES_CHANNEL_ID);

        return new RobotsPageLoadedEvent($page, Context::createDefaultContext(), $request);
    }

    private function blockFor(RobotsPage $page, string $userAgent): ?RobotsUserAgentBlock
    {
        foreach ($page->getGlobalUserAgentBlocks() as $block) {
            if ($block->userAgent === $userAgent) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function rendered(RobotsPage $page, string $userAgent): array
    {
        $block = $this->blockFor($page, $userAgent);
        self::assertNotNull($block);

        return array_map(static fn (RobotsDirective $d): string => $d->render(), $block->directives);
    }
}
