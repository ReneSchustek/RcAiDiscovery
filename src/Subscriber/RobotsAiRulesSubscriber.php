<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Subscriber;

use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawler;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawlerCatalog;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiRulesConfigProvider;
use Ruhrcoder\RcAiDiscovery\Service\Robots\HostSalesChannelResolver;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Page\Robots\RobotsPageLoadedEvent;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirective;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirectiveType;
use Shopware\Storefront\Page\Robots\Struct\RobotsUserAgentBlock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ergänzt die robots.txt um KI-Crawler-Regeln, sobald der Betreiber sie eingeschaltet hat.
 *
 * Die Kern-Vorlage gibt `page.globalUserAgentBlocks` schon aus. Der Subscriber hängt seine Blöcke
 * deshalb an die geladene Seite, statt die Vorlage per `sw_extends` zu überschreiben: Das kollidiert
 * mit keinem anderen Plugin, das dieselbe Vorlage erweitert, und wirkt ohne Zutun auch in der
 * Prüfung, die dieselbe Seite lädt. Im Staging-Modus bleibt es wirkungslos, weil der Kern dort nur
 * `Disallow: /` ausgibt und die globalen Blöcke weglässt.
 */
final class RobotsAiRulesSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AiCrawlerCatalog $catalog,
        private readonly AiRulesConfigProvider $configProvider,
        private readonly HostSalesChannelResolver $hostResolver,
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

        // Die Blöcke landen hinter denen des Shops, in der Reihenfolge der Gruppen wie in der Anzeige.
        foreach ([AiCrawlerCatalog::GROUP_SEARCH, AiCrawlerCatalog::GROUP_FETCH, AiCrawlerCatalog::GROUP_TRAINING] as $group) {
            $allow = $config->allows($group);

            foreach ($this->catalog->writableOfGroup($group) as $crawler) {
                // Ein Block, den der Betreiber in den robots.txt-Regeln des Shops selbst angelegt hat,
                // ist seine Entscheidung und geht der des Plugins vor.
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
     * Ein eigener User-agent-Block ersetzt für diesen Bot den Block `*` vollständig, samt der
     * Vorgaberegeln des Kerns. Ein Teil davon wird deshalb hier wiederholt: die Sperre für Adressen
     * mit Parametern und die Ausnahmen für Theme- und Mediendateien. Ohne sie liefen die KI-Crawler
     * als einzige in sämtliche Filter- und Sortieradressen.
     *
     * Gespiegelt ist der Stand von Kern 6.7.15: auch die Ausnahmen für die Rückverweise aus dem
     * Produktfeed (`referringSalesChannel=`) und für Vorschaubilder mit Cache-Buster.
     *
     * @return list<RobotsDirective>
     */
    private function allowDirectives(): array
    {
        return [
            new RobotsDirective(RobotsDirectiveType::ALLOW, '/'),
            new RobotsDirective(RobotsDirectiveType::DISALLOW, '/*?'),
            new RobotsDirective(RobotsDirectiveType::ALLOW, '/*referringSalesChannel='),
            new RobotsDirective(RobotsDirectiveType::ALLOW, '/*theme/'),
            new RobotsDirective(RobotsDirectiveType::ALLOW, '/media/*?ts='),
            new RobotsDirective(RobotsDirectiveType::ALLOW, '/thumbnail/*?ts='),
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
     * Klein geschrieben, weil User-Agents in der robots.txt ohne Rücksicht auf Groß- und
     * Kleinschreibung verglichen werden.
     *
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
     * Den Verkaufskanal setzt nur die Prüfung in der Verwaltung als Attribut. Der echte Abruf von
     * `/robots.txt` hat keinen, weil der Kern diesen Pfad ohne Verkaufskanal auflöst; dort wird er
     * über den Hostnamen gesucht, wie es der Kern für seine Domain-Regeln tut. Ohne diese Suche gälte
     * dort immer die globale Einstellung, und die Werte je Kanal kämen nie an.
     */
    private function salesChannelId(RobotsPageLoadedEvent $event): ?string
    {
        $request = $event->getRequest();
        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        if (\is_string($salesChannelId) && $salesChannelId !== '') {
            return $salesChannelId;
        }

        $host = $request->server->get('HTTP_HOST');

        return \is_string($host) ? $this->hostResolver->resolve($host) : null;
    }
}
