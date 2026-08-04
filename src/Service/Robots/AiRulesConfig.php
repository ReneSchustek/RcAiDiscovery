<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

/**
 * Die Admin-Entscheidung, welche KI-Zugriffe die robots.txt zulassen soll.
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
     * Solange nichts konfiguriert ist, schreibt das Plugin keine Regeln — die robots.txt bleibt
     * exakt die des Shops.
     */
    public static function disabled(): self
    {
        return new self(false, []);
    }

    public function allows(string $group): bool
    {
        return ($this->modes[$group] ?? self::MODE_ALLOW) === self::MODE_ALLOW;
    }
}
