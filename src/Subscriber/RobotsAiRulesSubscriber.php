<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Subscriber;

use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawler;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawlerCatalog;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiRulesConfigProvider;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Page\Robots\RobotsPageLoadedEvent;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirective;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirectiveType;
use Shopware\Storefront\Page\Robots\Struct\RobotsUserAgentBlock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ergänzt die robots.txt um KI-Crawler-Regeln, sobald der Betreiber sie eingeschaltet hat.
 *
 * Der Core rendert `page.globalUserAgentBlocks` bereits im eigenen Template — deshalb wird hier
 * nur die Seite angereichert, statt das Template per `sw_extends` zu überschreiben. Das vermeidet
 * Kollisionen mit anderen Plugins und wirkt automatisch auch im KI-Check, der dasselbe Template
 * rendert. Im Staging-Modus greift nichts davon, weil der Core die globalen Blöcke dort gar nicht
 * ausgibt (`Disallow: /` für alle).
 */
final class RobotsAiRulesSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AiCrawlerCatalog $catalog,
        private readonly AiRulesConfigProvider $configProvider,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [RobotsPageLoadedEvent::class => 'onRobotsPageLoaded'];
    }

    public function onRobotsPageLoaded(RobotsPageLoadedEvent $event): void
    {
        $config = $this->configProvider->load($this->salesChannelId($event));
        if (!$config->enabled) {
            return;
        }

        $page = $event->getPage();
        $blocks = $page->getGlobalUserAgentBlocks();
        $existing = $this->userAgentsOf($blocks);

        foreach ([AiCrawlerCatalog::GROUP_SEARCH, AiCrawlerCatalog::GROUP_FETCH, AiCrawlerCatalog::GROUP_TRAINING] as $group) {
            $allow = $config->allows($group);

            foreach ($this->catalog->writableOfGroup($group) as $crawler) {
                // Eine im Shop gepflegte Regel ist die bewusste Entscheidung des Betreibers und
                // hat Vorrang vor der des Plugins.
                if (isset($existing[mb_strtolower($crawler->token)])) {
                    continue;
                }

                $blocks[] = $this->buildBlock($crawler, $allow);
            }
        }

        $page->setGlobalUserAgentBlocks($blocks);
    }

    private function buildBlock(AiCrawler $crawler, bool $allow): RobotsUserAgentBlock
    {
        return new RobotsUserAgentBlock($crawler->token, $allow ? $this->allowDirectives() : $this->blockDirectives());
    }

    /**
     * Ein eigener User-agent-Block ersetzt für diesen Bot den Sammelblock vollständig — inklusive
     * der Core-Schutzregeln. Sie werden deshalb mitgespiegelt, sonst liefen die KI-Crawler als
     * einzige in sämtliche Filter- und Sortier-URLs.
     *
     * @return list<RobotsDirective>
     */
    private function allowDirectives(): array
    {
        return [
            new RobotsDirective(RobotsDirectiveType::ALLOW, '/'),
            new RobotsDirective(RobotsDirectiveType::DISALLOW, '/*?'),
            new RobotsDirective(RobotsDirectiveType::ALLOW, '/*theme/'),
            new RobotsDirective(RobotsDirectiveType::ALLOW, '/media/*?ts='),
        ];
    }

    /**
     * @return list<RobotsDirective>
     */
    private function blockDirectives(): array
    {
        return [new RobotsDirective(RobotsDirectiveType::DISALLOW, '/')];
    }

    /**
     * @param list<RobotsUserAgentBlock> $blocks
     *
     * @return array<string, true>
     */
    private function userAgentsOf(array $blocks): array
    {
        $userAgents = [];
        foreach ($blocks as $block) {
            $userAgents[mb_strtolower($block->userAgent)] = true;
        }

        return $userAgents;
    }

    /**
     * Der Storefront-Request trägt den Sales-Channel als Attribut; fehlt es (etwa beim internen
     * Rendern für den Admin-Check ohne Kanal-Bezug), greift die globale Konfiguration.
     */
    private function salesChannelId(RobotsPageLoadedEvent $event): ?string
    {
        $salesChannelId = $event->getRequest()->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);

        return \is_string($salesChannelId) && $salesChannelId !== '' ? $salesChannelId : null;
    }
}
