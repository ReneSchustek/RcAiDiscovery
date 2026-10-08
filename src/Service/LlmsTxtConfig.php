<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service;

/**
 * Die in der Plugin-Konfiguration gepflegten Vorgaben für die llms-Dateien eines Verkaufskanals.
 *
 * Jedes Feld ist schon normalisiert. `null` heißt „nicht gesetzt, automatisch ermitteln", sodass der
 * Generator leere oder nur aus Leerzeichen bestehende Eingaben nie selbst prüfen muss.
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
     * Keine Vorgabe gepflegt: Alle Inhalte kommen aus den Shop-Daten.
     */
    public static function auto(): self
    {
        return new self(null, null, null);
    }
}
