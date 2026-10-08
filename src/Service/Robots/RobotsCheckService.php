<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Page\Robots\Parser\RobotsDirectiveParser;

/**
 * Prüft für alle aktiven Storefront-Kanäle, welche KI-Crawler die robots.txt zulässt: Text
 * beschaffen (`EffectiveRobotsTxtProvider`), mit dem Parser des Kerns zerlegen, mit
 * `RobotsAiCrawlerEvaluator` bewerten.
 *
 * Jeder Kanal bekommt ein Ergebnis, auch wenn seine Prüfung scheitert; dann stehen alle Crawler
 * auf „unbekannt" mit dem Grund. Ein kaputter Kanal leert so nicht die ganze Anzeige.
 */
final class RobotsCheckService
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly EffectiveRobotsTxtProvider $robotsTxtProvider,
        private readonly RobotsDirectiveParser $parser,
        private readonly RobotsAiCrawlerEvaluator $evaluator,
        private readonly AiCrawlerCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<SalesChannelRobotsStatus>
     */
    public function checkAllStorefronts(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('domains');

        $results = [];
        foreach ($this->salesChannelRepository->search($criteria, $context)->getEntities() as $salesChannel) {
            $results[] = $this->checkSalesChannel($salesChannel, $context);
        }

        return $results;
    }

    private function checkSalesChannel(SalesChannelEntity $salesChannel, Context $context): SalesChannelRobotsStatus
    {
        // Ohne Übersetzung in der Sprache der Verwaltung zeigt die Anzeige wenigstens die Kennung.
        $name = $salesChannel->getName() ?? $salesChannel->getId();
        $url = $this->firstDomainUrl($salesChannel);

        if ($url === null) {
            return $this->unknown($salesChannel->getId(), $name, null, CrawlerStatus::REASON_NO_DOMAIN);
        }

        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return $this->unknown($salesChannel->getId(), $name, $url, CrawlerStatus::REASON_HOST_UNREADABLE);
        }

        try {
            $robotsTxt = $this->robotsTxtProvider->render($host, $context, $salesChannel->getId());
            if ($robotsTxt === null) {
                return $this->unknown($salesChannel->getId(), $name, $url, CrawlerStatus::REASON_ROBOTS_UNAVAILABLE);
            }

            $parsed = $this->parser->parse($robotsTxt, $context, $salesChannel->getId());

            return new SalesChannelRobotsStatus($salesChannel->getId(), $name, $url, $this->evaluator->evaluate($parsed));
        } catch (\Throwable $exception) {
            // Ein Fehler in einem Kanal darf die Prüfung der übrigen nicht abbrechen. Er landet im
            // Protokoll, in der Anzeige steht der Kanal auf „unbekannt".
            $this->logger->error('robots.txt-KI-Check für Sales-Channel fehlgeschlagen', [
                'salesChannelId' => $salesChannel->getId(),
                'host' => $host,
                'exception' => $exception,
            ]);

            return $this->unknown($salesChannel->getId(), $name, $url, CrawlerStatus::REASON_CHECK_FAILED);
        }
    }

    /**
     * Geprüft wird nur der Host einer Domain des Kanals, weil die robots.txt je Host ausgeliefert
     * wird und die Kanäle meist nur einen haben. Welche Domain das ist, legt die Abfrage nicht fest:
     * Die Assoziation hat keine Sortierung.
     */
    private function firstDomainUrl(SalesChannelEntity $salesChannel): ?string
    {
        $domains = $salesChannel->getDomains();
        if ($domains === null) {
            return null;
        }

        return $domains->first()?->getUrl();
    }

    private function unknown(string $salesChannelId, string $name, ?string $url, string $reasonCode): SalesChannelRobotsStatus
    {
        $crawlers = [];
        foreach ($this->catalog->all() as $crawler) {
            $crawlers[] = new CrawlerStatus($crawler->token, CrawlerStatus::UNKNOWN, $reasonCode, $crawler->group, $crawler->noteCode);
        }

        return new SalesChannelRobotsStatus($salesChannelId, $name, $url, $crawlers);
    }
}
