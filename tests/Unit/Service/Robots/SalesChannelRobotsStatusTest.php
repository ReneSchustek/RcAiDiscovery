<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Service\Robots;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\Service\Robots\AiCrawlerCatalog;
use Ruhrcoder\RcAiDiscovery\Service\Robots\CrawlerStatus;
use Ruhrcoder\RcAiDiscovery\Service\Robots\SalesChannelRobotsStatus;

final class SalesChannelRobotsStatusTest extends TestCase
{
    public function testCountsBlockedAndUnknown(): void
    {
        $status = new SalesChannelRobotsStatus('sc-1', 'Kanal', 'https://shop.example', [
            new CrawlerStatus('A', CrawlerStatus::ALLOWED, CrawlerStatus::REASON_ALLOWED_DEFAULT, AiCrawlerCatalog::GROUP_SEARCH),
            new CrawlerStatus('B', CrawlerStatus::BLOCKED, CrawlerStatus::REASON_BLOCKED_OWN, AiCrawlerCatalog::GROUP_SEARCH),
            new CrawlerStatus('C', CrawlerStatus::BLOCKED, CrawlerStatus::REASON_BLOCKED_WILDCARD, AiCrawlerCatalog::GROUP_SEARCH),
            new CrawlerStatus('D', CrawlerStatus::UNKNOWN, CrawlerStatus::REASON_NO_DOMAIN, AiCrawlerCatalog::GROUP_SEARCH),
        ]);

        self::assertSame(2, $status->blockedCount());
        self::assertSame(1, $status->unknownCount());
    }

    public function testJsonSerializeExposesCounts(): void
    {
        $json = (new SalesChannelRobotsStatus('sc-1', 'Kanal', null, []))->jsonSerialize();

        self::assertSame('sc-1', $json['salesChannelId']);
        self::assertNull($json['url']);
        self::assertSame(0, $json['blockedCount']);
        self::assertSame(0, $json['unknownCount']);
    }
}
