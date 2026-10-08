<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

/**
 * Ergebnis der robots.txt-Prüfung eines Verkaufskanals: je Crawler ein Status und die Zahl der
 * gesperrten und unbekannten. Die Zahlen gehen mit in die JSON-Antwort für andere Abnehmer der
 * Admin-API; die mitgelieferte Komponente liest sie nicht. `url` ist `null`, wenn der Kanal keine
 * Domain hat.
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
