<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

/**
 * Kuratierter Katalog der KI-Crawler, die das Plugin prüft und für die es Regeln schreibt.
 * Beleg: Crawler-Dokumentation der jeweiligen Anbieter, abgerufen am 27.07.2026.
 *
 * Die Bots sind nach Zweck gruppiert, weil sie für einen Shop unterschiedlich viel wert sind. Suche
 * und Abruf bringen Sichtbarkeit, weil der Shop zitiert und verlinkt wird; Training bringt keine.
 * Nur so lässt sich „gefunden werden" getrennt von „Inhalte fürs Modelltraining abgeben" regeln.
 *
 * Anbieter führen mehrmals im Jahr neue Tokens ein. Ein neuer Eintrag in `build()` genügt, Prüfung,
 * Regeln und Anzeige richten sich danach.
 */
final class AiCrawlerCatalog
{
    /**
     * Indexieren für KI-Antworten; über sie wird ein Shop zitiert.
     */
    public const GROUP_SEARCH = 'search';

    /**
     * Holen eine Seite, weil ein Nutzer gerade danach fragt. Das ist direkter Kundenkontakt.
     */
    public const GROUP_FETCH = 'fetch';

    /**
     * Sammeln Material für das Modelltraining und bringen dem Shop keine Sichtbarkeit.
     */
    public const GROUP_TRAINING = 'training';

    /**
     * Abgelöstes Token: bleibt zur Auswertung bestehender robots.txt im Katalog, bekommt aber
     * keine neu geschriebene Regel mehr.
     */
    public const NOTE_LEGACY_TOKEN = 'legacy_token';

    /**
     * Derselbe Bot trägt auch die klassische Suchmaschine. Eine eigene Regel würde sie mitregeln,
     * deshalb wird er nur ausgewertet.
     */
    public const NOTE_DUAL_PURPOSE = 'dual_purpose';

    /**
     * Beim ersten Zugriff gebaut und danach wiederverwendet; die Prüfung fragt den Katalog je
     * Verkaufskanal erneut ab.
     *
     * @var list<AiCrawler>|null
     */
    private ?array $crawlers = null;

    /**
     * @return list<AiCrawler>
     */
    public function all(): array
    {
        return $this->crawlers ??= $this->build();
    }

    /**
     * Die Crawler einer Gruppe, für die das Plugin Regeln schreiben darf.
     *
     * @return list<AiCrawler>
     */
    public function writableOfGroup(string $group): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (AiCrawler $crawler): bool => $crawler->group === $group && $crawler->writesRule
        ));
    }

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        return array_map(static fn (AiCrawler $crawler): string => $crawler->token, $this->all());
    }

    /**
     * Die Reihenfolge innerhalb einer Gruppe ist die der Anzeige in der Verwaltung.
     *
     * @return list<AiCrawler>
     */
    private function build(): array
    {
        return [
            // Suche und Zitation
            new AiCrawler('OAI-SearchBot', self::GROUP_SEARCH),
            new AiCrawler('Claude-SearchBot', self::GROUP_SEARCH),
            new AiCrawler('PerplexityBot', self::GROUP_SEARCH),
            new AiCrawler('DuckAssistBot', self::GROUP_SEARCH),
            new AiCrawler('Applebot', self::GROUP_SEARCH),
            new AiCrawler('Amazonbot', self::GROUP_SEARCH),
            new AiCrawler('Bravebot', self::GROUP_SEARCH),
            new AiCrawler('GoogleOther', self::GROUP_SEARCH),
            new AiCrawler('Bingbot', self::GROUP_SEARCH, false, self::NOTE_DUAL_PURPOSE),

            // Abruf auf Nutzerwunsch
            new AiCrawler('ChatGPT-User', self::GROUP_FETCH),
            new AiCrawler('Claude-User', self::GROUP_FETCH),
            new AiCrawler('Perplexity-User', self::GROUP_FETCH),
            new AiCrawler('MistralAI-User', self::GROUP_FETCH),
            new AiCrawler('Meta-ExternalFetcher', self::GROUP_FETCH),
            new AiCrawler('Google-CloudVertexBot', self::GROUP_FETCH),
            new AiCrawler('cohere-ai', self::GROUP_FETCH),
            new AiCrawler('kagi-fetcher', self::GROUP_FETCH),
            new AiCrawler('Claude-Web', self::GROUP_FETCH, false, self::NOTE_LEGACY_TOKEN),

            // Training
            new AiCrawler('GPTBot', self::GROUP_TRAINING),
            new AiCrawler('ClaudeBot', self::GROUP_TRAINING),
            new AiCrawler('Google-Extended', self::GROUP_TRAINING),
            new AiCrawler('Applebot-Extended', self::GROUP_TRAINING),
            new AiCrawler('Meta-ExternalAgent', self::GROUP_TRAINING),
            new AiCrawler('CCBot', self::GROUP_TRAINING),
            new AiCrawler('Bytespider', self::GROUP_TRAINING),
            new AiCrawler('AI2Bot', self::GROUP_TRAINING),
            new AiCrawler('cohere-training-data-crawler', self::GROUP_TRAINING),
            new AiCrawler('anthropic-ai', self::GROUP_TRAINING, false, self::NOTE_LEGACY_TOKEN),
        ];
    }
}
