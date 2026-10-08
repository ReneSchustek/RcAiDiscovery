<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

/**
 * Die Entscheidung des Betreibers, welche KI-Zugriffe die robots.txt zulassen soll, je
 * Crawler-Gruppe.
 */
final class AiRulesConfig
{
    public const MODE_ALLOW = 'allow';

    public const MODE_BLOCK = 'block';

    /**
     * @param array<string, string> $modes Gruppe (AiCrawlerCatalog::GROUP_*) => MODE_ALLOW|MODE_BLOCK
     */
    public function __construct(
        public readonly bool $enabled,
        private readonly array $modes,
    ) {
    }

    /**
     * Solange der Schalter `aiRulesEnabled` aus ist, schreibt das Plugin keine Regeln, und die
     * robots.txt bleibt genau die des Shops.
     */
    public static function disabled(): self
    {
        return new self(false, []);
    }

    /**
     * Eine Gruppe ohne Eintrag gilt als erlaubt, aus demselben Grund wie in
     * `AiRulesConfigProvider::mode()`.
     */
    public function allows(string $group): bool
    {
        return ($this->modes[$group] ?? self::MODE_ALLOW) === self::MODE_ALLOW;
    }
}
