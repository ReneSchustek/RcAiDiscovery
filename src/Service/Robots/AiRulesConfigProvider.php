<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die KI-Regel-Einstellungen aus der Plugin-Konfiguration des Sales-Channels.
 */
final class AiRulesConfigProvider
{
    public const KEY_ENABLED = 'RcAiDiscovery.config.aiRulesEnabled';

    private const KEY_BY_GROUP = [
        AiCrawlerCatalog::GROUP_SEARCH => 'RcAiDiscovery.config.aiRulesSearch',
        AiCrawlerCatalog::GROUP_FETCH => 'RcAiDiscovery.config.aiRulesFetch',
        AiCrawlerCatalog::GROUP_TRAINING => 'RcAiDiscovery.config.aiRulesTraining',
    ];

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function load(?string $salesChannelId): AiRulesConfig
    {
        if (!$this->systemConfigService->getBool(self::KEY_ENABLED, $salesChannelId)) {
            return AiRulesConfig::disabled();
        }

        $modes = [];
        foreach (self::KEY_BY_GROUP as $group => $key) {
            $modes[$group] = $this->mode($this->systemConfigService->getString($key, $salesChannelId));
        }

        return new AiRulesConfig(true, $modes);
    }

    /**
     * Unbekannte oder leere Werte gelten als „erlauben" — der Zweck des Plugins ist Sichtbarkeit,
     * eine stille Sperre wäre die überraschende Auslegung.
     */
    private function mode(string $configured): string
    {
        return $configured === AiRulesConfig::MODE_BLOCK ? AiRulesConfig::MODE_BLOCK : AiRulesConfig::MODE_ALLOW;
    }
}
