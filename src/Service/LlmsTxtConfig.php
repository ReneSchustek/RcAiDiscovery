<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service;

/**
 * Die im Admin gepflegten Overrides für die llms.txt eines Sales-Channels.
 *
 * Jedes Feld ist bereits normalisiert: `null` bedeutet „nicht gesetzt" und damit
 * „automatisch ermitteln" — der Generator entscheidet nicht mehr über leere Strings.
 */
final class LlmsTxtConfig
{
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $summary,
        public readonly ?string $additionalContent,
    ) {
    }

    /**
     * Kein Override gepflegt: alle Inhalte kommen aus den Shop-Daten.
     */
    public static function auto(): self
    {
        return new self(null, null, null);
    }
}
