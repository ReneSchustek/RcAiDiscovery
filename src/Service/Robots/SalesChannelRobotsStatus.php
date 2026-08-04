<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

/**
 * Aggregierter robots.txt-KI-Status eines Sales-Channels: pro Crawler ein Status plus Kennzahlen.
 */
final class SalesChannelRobotsStatus implements \JsonSerializable
{
    /**
     * @param list<CrawlerStatus> $crawlers
     */
    public function __construct(
        public readonly string $salesChannelId,
        public readonly string $name,
        public readonly ?string $url,
        public readonly array $crawlers,
    ) {
    }

    public function blockedCount(): int
    {
        return \count(array_filter($this->crawlers, static fn (CrawlerStatus $c): bool => $c->status === CrawlerStatus::BLOCKED));
    }

    public function unknownCount(): int
    {
        return \count(array_filter($this->crawlers, static fn (CrawlerStatus $c): bool => $c->status === CrawlerStatus::UNKNOWN));
    }

    /**
     * @return array{salesChannelId: string, name: string, url: string|null, crawlers: list<CrawlerStatus>, blockedCount: int, unknownCount: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'salesChannelId' => $this->salesChannelId,
            'name' => $this->name,
            'url' => $this->url,
            'crawlers' => $this->crawlers,
            'blockedCount' => $this->blockedCount(),
            'unknownCount' => $this->unknownCount(),
        ];
    }
}
