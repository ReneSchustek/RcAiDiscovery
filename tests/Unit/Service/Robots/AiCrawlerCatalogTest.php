<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Service\Robots;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawler;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawlerCatalog;

final class AiCrawlerCatalogTest extends TestCase
{
    public function testTokensAreUnique(): void
    {
        $tokens = (new AiCrawlerCatalog())->tokens();

        self::assertSame(
            array_values(array_unique(array_map('mb_strtolower', $tokens))),
            array_map('mb_strtolower', $tokens),
            'Doppelte Tokens würden doppelte robots.txt-Blöcke erzeugen.'
        );
    }

    public function testEveryGroupIsPopulated(): void
    {
        $catalog = new AiCrawlerCatalog();

        foreach ([AiCrawlerCatalog::GROUP_SEARCH, AiCrawlerCatalog::GROUP_FETCH, AiCrawlerCatalog::GROUP_TRAINING] as $group) {
            self::assertNotEmpty($catalog->writableOfGroup($group), 'Gruppe ohne Crawler: ' . $group);
        }
    }

    public function testEveryCrawlerBelongsToAKnownGroup(): void
    {
        $known = [AiCrawlerCatalog::GROUP_SEARCH, AiCrawlerCatalog::GROUP_FETCH, AiCrawlerCatalog::GROUP_TRAINING];

        foreach ((new AiCrawlerCatalog())->all() as $crawler) {
            self::assertContains($crawler->group, $known, $crawler->token . ' hat keine bekannte Gruppe.');
        }
    }

    /**
     * Abgelöste Tokens werden weiter ausgewertet (bestehende robots.txt), aber nie neu geschrieben.
     */
    public function testLegacyTokensAreEvaluatedButNeverWritten(): void
    {
        $legacy = $this->crawlersWithNote(AiCrawlerCatalog::NOTE_LEGACY_TOKEN);

        self::assertNotEmpty($legacy);
        foreach ($legacy as $crawler) {
            self::assertFalse($crawler->writesRule, $crawler->token . ' ist abgelöst und darf keine Regel schreiben.');
        }
        self::assertContains('anthropic-ai', array_map(static fn (AiCrawler $c): string => $c->token, $legacy));
    }

    /**
     * Bingbot trägt Copilot, ist aber zugleich die normale Bing-Suche — eine eigene Regel würde
     * die klassische Suchmaschine mitregeln.
     */
    public function testDualPurposeCrawlerIsCheckedOnly(): void
    {
        $dualPurpose = $this->crawlersWithNote(AiCrawlerCatalog::NOTE_DUAL_PURPOSE);

        self::assertNotEmpty($dualPurpose);
        foreach ($dualPurpose as $crawler) {
            self::assertFalse($crawler->writesRule);
        }
        self::assertNotContains('Bingbot', array_map(
            static fn (AiCrawler $c): string => $c->token,
            (new AiCrawlerCatalog())->writableOfGroup(AiCrawlerCatalog::GROUP_SEARCH)
        ));
    }

    public function testWritableOfGroupReturnsOnlyThatGroup(): void
    {
        foreach ((new AiCrawlerCatalog())->writableOfGroup(AiCrawlerCatalog::GROUP_TRAINING) as $crawler) {
            self::assertSame(AiCrawlerCatalog::GROUP_TRAINING, $crawler->group);
            self::assertTrue($crawler->writesRule);
        }
    }

    /**
     * @return list<AiCrawler>
     */
    private function crawlersWithNote(string $noteCode): array
    {
        return array_values(array_filter(
            (new AiCrawlerCatalog())->all(),
            static fn (AiCrawler $crawler): bool => $crawler->noteCode === $noteCode
        ));
    }
}
