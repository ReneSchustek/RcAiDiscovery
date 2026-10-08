<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Erzeugt die gespeicherten llms-Dateien regelmäßig neu, damit neue oder umbenannte Kategorien
 * und geänderte Einstellungen ohne Handgriff in der Datei ankommen.
 */
final class LlmsGenerateTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_ai_discovery.llms_generate';
    }

    /**
     * Täglich: Kategoriebaum und Beschreibungen ändern sich selten, und wer eine Änderung sofort
     * in der Datei braucht, löst sie in der Plugin-Konfiguration mit „Jetzt aktualisieren" aus.
     */
    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    /**
     * Ohne Neueinplanung bliebe die Aufgabe nach einem einzigen Fehlschlag stehen, und die Dateien
     * veralteten still.
     */
    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
