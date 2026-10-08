<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die Vorgaben für die llms-Dateien aus der Plugin-Konfiguration des Verkaufskanals und
 * normalisiert sie, damit der Generator nur fertige Werte verarbeitet.
 *
 * Gelesen wird beim Generieren. Eine geänderte Einstellung erscheint deshalb erst mit dem nächsten
 * Lauf der geplanten Aufgabe oder nach „Jetzt aktualisieren" in der Datei.
 */
final class LlmsTxtConfigProvider
{
    public const KEY_TITLE = 'RcAiDiscovery.config.llmsTitle';

    public const KEY_SUMMARY = 'RcAiDiscovery.config.llmsSummary';

    public const KEY_ADDITIONAL_CONTENT = 'RcAiDiscovery.config.llmsAdditionalContent';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function load(string $salesChannelId): LlmsTxtConfig
    {
        return new LlmsTxtConfig(
            $this->singleLineOrNull($this->read(self::KEY_TITLE, $salesChannelId)),
            $this->singleLineOrNull($this->read(self::KEY_SUMMARY, $salesChannelId)),
            $this->markdownBlockOrNull($this->read(self::KEY_ADDITIONAL_CONTENT, $salesChannelId)),
        );
    }

    private function read(string $key, string $salesChannelId): string
    {
        return $this->systemConfigService->getString($key, $salesChannelId);
    }

    /**
     * Titel und Kurzbeschreibung stehen in einzeiligen Markdown-Formen („# …", „> …"). Ein
     * Zeilenumbruch würde sie zerbrechen, deshalb wird jeder Leerraum zu einem Leerzeichen.
     */
    private function singleLineOrNull(string $value): ?string
    {
        $singleLine = trim((string) preg_replace('/\s+/', ' ', $value));

        return $singleLine === '' ? null : $singleLine;
    }

    /**
     * Der Zusatz-Inhalt ist vom Betreiber gestalteter Markdown. Nur Zeilenenden werden vereinheitlicht
     * und der Rand gekürzt, Zeilen und Einrückung bleiben, wie sie sind.
     */
    private function markdownBlockOrNull(string $value): ?string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return $normalized === '' ? null : $normalized;
    }
}
