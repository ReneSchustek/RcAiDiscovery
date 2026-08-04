<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Erzeugt die gespeicherten llms-Dateien turnusmäßig neu — analog zum Produktfeed.
 */
final class LlmsGenerateTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_ai_discovery.llms_generate';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
