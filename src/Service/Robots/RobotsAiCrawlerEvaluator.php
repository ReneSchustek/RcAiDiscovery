<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

use Shopware\Storefront\Page\Robots\Parser\ParsedRobots;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirectiveType;

/**
 * Bewertet für jeden KI-Crawler, ob er die Startseite (Pfad „/") crawlen darf.
 *
 * Der Core-Parser liefert nur die geparsten Blöcke, aber keine Allow/Disallow-Entscheidung —
 * diese Matching-Logik ist deshalb hier implementiert: die auf den Crawler anwendbaren Direktiven
 * bestimmen (exakter, case-insensitiver User-Agent-Match; sonst der „*"-Block) und den Pfad „/"
 * per Longest-Match nach robots.txt-Semantik (inkl. `*`/`$`-Wildcards) auswerten.
 */
final class RobotsAiCrawlerEvaluator
{
    private const ROOT_PATH = '/';

    private const WILDCARD_USER_AGENT = '*';

    public function __construct(private readonly AiCrawlerCatalog $catalog)
    {
    }

    /**
     * @param list<AiCrawler>|null $crawlers Teilmenge zum Testen; Standard ist der vollständige Katalog
     *
     * @return list<CrawlerStatus>
     */
    public function evaluate(ParsedRobots $parsed, ?array $crawlers = null): array
    {
        $statuses = [];
        foreach ($crawlers ?? $this->catalog->all() as $crawler) {
            $statuses[] = $this->evaluateCrawler($crawler, $parsed);
        }

        return $statuses;
    }

    private function evaluateCrawler(AiCrawler $crawler, ParsedRobots $parsed): CrawlerStatus
    {
        $resolution = $this->resolveDirectives($crawler->token, $parsed);

        // robots.txt trifft keine Aussage zu diesem Crawler → standardmäßig erlaubt.
        if (!$resolution->hasBlock) {
            return $this->status($crawler, CrawlerStatus::ALLOWED, CrawlerStatus::REASON_ALLOWED_DEFAULT);
        }

        if ($this->isRootAllowed($resolution->directives)) {
            $reason = $resolution->viaOwnBlock ? CrawlerStatus::REASON_ALLOWED_OWN : CrawlerStatus::REASON_ALLOWED_WILDCARD;

            return $this->status($crawler, CrawlerStatus::ALLOWED, $reason);
        }

        $reason = $resolution->viaOwnBlock ? CrawlerStatus::REASON_BLOCKED_OWN : CrawlerStatus::REASON_BLOCKED_WILDCARD;

        return $this->status($crawler, CrawlerStatus::BLOCKED, $reason);
    }

    private function status(AiCrawler $crawler, string $status, string $reasonCode): CrawlerStatus
    {
        return new CrawlerStatus($crawler->token, $status, $reasonCode, $crawler->group, $crawler->noteCode);
    }

    /**
     * Sammelt die anwendbaren Pfad-Direktiven: alle exakt passenden User-Agent-Blöcke (case-insensitiv)
     * werden zusammengeführt; gibt es keinen, greifen die „*"-Blöcke.
     */
    private function resolveDirectives(string $token, ParsedRobots $parsed): DirectiveResolution
    {
        $ownDirectives = [];
        $wildcardDirectives = [];
        $hasOwnBlock = false;
        $hasWildcardBlock = false;

        foreach ($parsed->userAgentBlocks as $block) {
            if (strcasecmp($block->userAgent, $token) === 0) {
                $hasOwnBlock = true;
                array_push($ownDirectives, ...$block->getPathDirectives());
            } elseif ($block->userAgent === self::WILDCARD_USER_AGENT) {
                $hasWildcardBlock = true;
                array_push($wildcardDirectives, ...$block->getPathDirectives());
            }
        }

        if ($hasOwnBlock) {
            return new DirectiveResolution($ownDirectives, true, true);
        }

        if ($hasWildcardBlock) {
            return new DirectiveResolution($wildcardDirectives, false, true);
        }

        return new DirectiveResolution([], false, false);
    }

    /**
     * Longest-Match für den Pfad „/": längstes tatsächlich passendes Muster gewinnt, bei Gleichstand Allow.
     * Leeres Disallow („Disallow:") bedeutet „alles erlaubt".
     *
     * @param list<\Shopware\Storefront\Page\Robots\Struct\RobotsDirective> $directives
     */
    private function isRootAllowed(array $directives): bool
    {
        $bestLength = -1;
        $bestType = null;

        foreach ($directives as $directive) {
            if (!$this->matchesRoot($directive->value)) {
                continue;
            }

            $length = mb_strlen($directive->value);
            if ($length > $bestLength || ($length === $bestLength && $directive->type === RobotsDirectiveType::ALLOW)) {
                $bestLength = $length;
                $bestType = $directive->type;
            }
        }

        if ($bestType === null) {
            return true;
        }

        // Nicht-leeres Disallow blockiert; leeres Disallow („Disallow:") erlaubt alles.
        if ($bestType === RobotsDirectiveType::DISALLOW) {
            return $bestLength === 0;
        }

        return true;
    }

    /**
     * Prüft nach robots.txt-Semantik, ob ein Pfad-Muster den Root-Pfad „/" matcht.
     * `*` steht für eine beliebige Zeichenfolge, ein abschließendes `$` verankert das URL-Ende.
     */
    private function matchesRoot(string $pattern): bool
    {
        if ($pattern === '') {
            return true;
        }

        $anchorEnd = str_ends_with($pattern, '$');
        if ($anchorEnd) {
            $pattern = substr($pattern, 0, -1);
        }

        $quoted = array_map(
            static fn (string $segment): string => preg_quote($segment, '#'),
            explode('*', $pattern)
        );

        $regex = '#^' . implode('.*', $quoted) . ($anchorEnd ? '$' : '') . '#';

        return preg_match($regex, self::ROOT_PATH) === 1;
    }
}
