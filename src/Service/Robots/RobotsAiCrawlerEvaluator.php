<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

use Shopware\Storefront\Page\Robots\Parser\ParsedRobots;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirectiveType;

/**
 * Bewertet für jeden KI-Crawler, ob er die Startseite, also den Pfad `/`, abrufen darf.
 *
 * Der Parser des Kerns liefert nur die Blöcke, keine Entscheidung. Die Zuordnung steht deshalb hier,
 * nach den Regeln von RFC 9309: Anwendbar sind alle Blöcke mit exakt passendem User-Agent ohne
 * Rücksicht auf Groß- und Kleinschreibung, nur wenn es keinen gibt, die Blöcke für `*`. Über den
 * Pfad entscheidet die längste passende Regel, Platzhalter `*` und Endanker `$` eingeschlossen.
 *
 * Geprüft wird nur `/`. Das reicht für die Frage „darf der Bot überhaupt in den Shop", nicht für
 * einzelne gesperrte Bereiche.
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

        // Sagt die robots.txt nichts zu diesem Crawler, darf er alles.
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
     * Sammelt die anwendbaren Pfad-Direktiven. Mehrere exakt passende Blöcke werden zusammengeführt,
     * ebenso mehrere `*`-Blöcke. Ein eigener Block verdrängt `*` ganz; es wird nicht gemischt.
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
     * Das längste passende Muster gewinnt, bei gleicher Länge `Allow`, wie RFC 9309 es vorsieht.
     * Gemessen wird die Länge des Musters samt `*` und `$`. Passt gar keine Regel, ist der Pfad frei.
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

        // Ein leeres `Disallow:` passt nach `matchesRoot()` immer, sperrt aber nichts. Gewinnt es mit
        // Länge 0, ist der Pfad frei.
        if ($bestType === RobotsDirectiveType::DISALLOW) {
            return $bestLength === 0;
        }

        return true;
    }

    /**
     * Prüft, ob ein Muster auf `/` passt. `*` steht für eine beliebige Zeichenfolge, ein `$` am Ende
     * verankert das Ende der Adresse; ohne `$` reicht ein passender Anfang.
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
