<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die Admin-Overrides für die llms.txt aus der Plugin-Konfiguration des Sales-Channels
 * und normalisiert sie, damit der Generator nur noch fertige Werte verarbeitet (SoC).
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
     * Titel und Kurzbeschreibung stehen in einer einzeiligen Markdown-Struktur („# …", „> …") —
     * Zeilenumbrüche würden sie zerbrechen, deshalb wird der Wert zu einer Zeile verdichtet.
     */
    private function singleLineOrNull(string $value): ?string
    {
        $singleLine = trim((string) preg_replace('/\s+/', ' ', $value));

        return $singleLine === '' ? null : $singleLine;
    }

    /**
     * Der Zusatz-Inhalt ist bewusst gestalteter Markdown des Betreibers: nur Zeilenenden
     * vereinheitlichen und den Rand trimmen, die Struktur bleibt unangetastet.
     */
    private function markdownBlockOrNull(string $value): ?string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return $normalized === '' ? null : $normalized;
    }
}
