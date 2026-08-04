<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Service\Robots;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawler;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawlerCatalog;
use Ruhrcoder\RcAiDiscovery\Service\Robots\CrawlerStatus;
use Ruhrcoder\RcAiDiscovery\Service\Robots\RobotsAiCrawlerEvaluator;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Page\Robots\Parser\RobotsDirectiveParser;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class RobotsAiCrawlerEvaluatorTest extends TestCase
{
    /**
     * Entspricht dem Default-Output des Core-Templates (mit Leerzeilen zwischen den Direktiven).
     */
    private const CORE_DEFAULT = "User-agent: *\n\nAllow: /\n\nDisallow: /*?\n\nAllow: /*theme/\n\nAllow: /media/*?ts=\n";

    public function testCoreDefaultTemplateAllowsAllCrawlers(): void
    {
        $statuses = $this->evaluate(self::CORE_DEFAULT, ['GPTBot', 'ClaudeBot', 'PerplexityBot']);

        foreach ($statuses as $status) {
            self::assertSame(CrawlerStatus::ALLOWED, $status->status, $status->token . ' sollte erlaubt sein');
        }
    }

    public function testSpecificCrawlerDisallowedWhileOthersAllowed(): void
    {
        $robots = "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /\n";

        $statuses = $this->evaluate($robots, ['GPTBot', 'ClaudeBot']);

        self::assertSame(CrawlerStatus::BLOCKED, $this->statusOf($statuses, 'GPTBot'));
        self::assertSame(CrawlerStatus::ALLOWED, $this->statusOf($statuses, 'ClaudeBot'));
    }

    public function testGlobalDisallowBlocksAllCrawlers(): void
    {
        $robots = "User-agent: *\nDisallow: /\n";

        $statuses = $this->evaluate($robots, ['GPTBot', 'ClaudeBot', 'CCBot']);

        foreach ($statuses as $status) {
            self::assertSame(CrawlerStatus::BLOCKED, $status->status, $status->token . ' sollte blockiert sein');
        }
    }

    public function testEmptyRobotsAllowsAll(): void
    {
        $statuses = $this->evaluate('', ['GPTBot', 'ClaudeBot']);

        self::assertSame(CrawlerStatus::ALLOWED, $this->statusOf($statuses, 'GPTBot'));
        self::assertSame(CrawlerStatus::ALLOWED, $this->statusOf($statuses, 'ClaudeBot'));
    }

    public function testEmptyDisallowMeansAllow(): void
    {
        $robots = "User-agent: *\nDisallow:\n";

        $statuses = $this->evaluate($robots, ['GPTBot']);

        self::assertSame(CrawlerStatus::ALLOWED, $this->statusOf($statuses, 'GPTBot'));
    }

    public function testUserAgentMatchIsCaseInsensitive(): void
    {
        $robots = "User-agent: gptbot\nDisallow: /\n\nUser-agent: *\nAllow: /\n";

        $statuses = $this->evaluate($robots, ['GPTBot']);

        self::assertSame(CrawlerStatus::BLOCKED, $this->statusOf($statuses, 'GPTBot'));
    }

    public function testEvaluatesEveryCatalogCrawler(): void
    {
        $catalog = new AiCrawlerCatalog();

        $statuses = $this->evaluate(self::CORE_DEFAULT);

        self::assertCount(\count($catalog->tokens()), $statuses);
        $tokens = array_map(static fn (CrawlerStatus $s): string => $s->token, $statuses);
        self::assertSame($catalog->tokens(), $tokens);
    }

    /**
     * Die Gruppe wandert aus dem Katalog bis in den Status durch — die Admin-Anzeige gruppiert danach.
     */
    public function testStatusCarriesGroupAndNoteFromCatalog(): void
    {
        $statuses = $this->evaluate(self::CORE_DEFAULT);

        $bingbot = $this->find($statuses, 'Bingbot');
        self::assertSame(AiCrawlerCatalog::GROUP_SEARCH, $bingbot->group);
        self::assertSame(AiCrawlerCatalog::NOTE_DUAL_PURPOSE, $bingbot->noteCode);

        self::assertSame(AiCrawlerCatalog::GROUP_TRAINING, $this->find($statuses, 'GPTBot')->group);
        self::assertNull($this->find($statuses, 'GPTBot')->noteCode);
    }

    /**
     * Regression: Ein Wildcard-Disallow mit Suffix (z. B. „Disallow: /*.pdf") matcht den Root-Pfad
     * NICHT und darf den Crawler nicht fälschlich als blockiert melden.
     */
    public function testWildcardDisallowWithSuffixDoesNotBlockRoot(): void
    {
        $robots = "User-agent: *\nDisallow: /*.pdf\n";

        $statuses = $this->evaluate($robots, ['GPTBot']);

        self::assertSame(CrawlerStatus::ALLOWED, $this->statusOf($statuses, 'GPTBot'));
    }

    public function testTrailingWildcardBlocksRoot(): void
    {
        $robots = "User-agent: *\nDisallow: /*\n";

        $statuses = $this->evaluate($robots, ['GPTBot']);

        self::assertSame(CrawlerStatus::BLOCKED, $this->statusOf($statuses, 'GPTBot'));
    }

    public function testNonRootDisallowDoesNotBlockRoot(): void
    {
        $robots = "User-agent: *\nDisallow: /admin\n";

        $statuses = $this->evaluate($robots, ['GPTBot']);

        self::assertSame(CrawlerStatus::ALLOWED, $this->statusOf($statuses, 'GPTBot'));
    }

    public function testEndAnchoredDisallowBlocksRoot(): void
    {
        $robots = "User-agent: *\nDisallow: /$\n";

        $statuses = $this->evaluate($robots, ['GPTBot']);

        self::assertSame(CrawlerStatus::BLOCKED, $this->statusOf($statuses, 'GPTBot'));
    }

    public function testMergesMultipleExactBlocksForSameToken(): void
    {
        // Zwei GPTBot-Blöcke; der zweite verbietet die Root. Beide müssen zusammengeführt werden.
        $robots = "User-agent: GPTBot\nAllow: /public\n\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /\n";

        $statuses = $this->evaluate($robots, ['GPTBot']);

        self::assertSame(CrawlerStatus::BLOCKED, $this->statusOf($statuses, 'GPTBot'));
    }

    public function testReasonCodesReflectSource(): void
    {
        self::assertSame(
            CrawlerStatus::REASON_ALLOWED_DEFAULT,
            $this->reasonOf($this->evaluate('', ['GPTBot']), 'GPTBot')
        );
        self::assertSame(
            CrawlerStatus::REASON_ALLOWED_WILDCARD,
            $this->reasonOf($this->evaluate("User-agent: *\nAllow: /\n", ['GPTBot']), 'GPTBot')
        );
        self::assertSame(
            CrawlerStatus::REASON_BLOCKED_OWN,
            $this->reasonOf($this->evaluate("User-agent: GPTBot\nDisallow: /\n", ['GPTBot']), 'GPTBot')
        );
    }

    /**
     * @param list<string>|null $tokens Teilmenge; null wertet den vollständigen Katalog aus
     *
     * @return list<CrawlerStatus>
     */
    private function evaluate(string $robotsTxt, ?array $tokens = null): array
    {
        $parser = new RobotsDirectiveParser(new EventDispatcher());
        $parsed = $parser->parse($robotsTxt, Context::createDefaultContext(), null);

        $crawlers = $tokens === null
            ? null
            : array_map(
                static fn (string $token): AiCrawler => new AiCrawler($token, AiCrawlerCatalog::GROUP_SEARCH),
                $tokens
            );

        return (new RobotsAiCrawlerEvaluator(new AiCrawlerCatalog()))->evaluate($parsed, $crawlers);
    }

    /**
     * @param list<CrawlerStatus> $statuses
     */
    private function statusOf(array $statuses, string $token): string
    {
        return $this->find($statuses, $token)->status;
    }

    /**
     * @param list<CrawlerStatus> $statuses
     */
    private function reasonOf(array $statuses, string $token): string
    {
        return $this->find($statuses, $token)->reasonCode;
    }

    /**
     * @param list<CrawlerStatus> $statuses
     */
    private function find(array $statuses, string $token): CrawlerStatus
    {
        foreach ($statuses as $status) {
            if ($status->token === $token) {
                return $status;
            }
        }

        self::fail('Crawler nicht im Ergebnis: ' . $token);
    }
}
