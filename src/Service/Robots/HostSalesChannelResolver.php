<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * Welcher Verkaufskanal gehört zu einem Hostnamen?
 *
 * `/robots.txt` löst der Kern ohne Verkaufskanal auf (`RequestTransformer::DOES_NOT_REQUIRE_SALESCHANNEL`); ohne
 * diesen Dienst kämen die KI-Regeln je Kanal nie an, es gälte immer die globale Einstellung. Der Kern selbst findet seine
 * Domain-Regeln über den Hostnamen (`RobotsPageLoader::getDomains()`), und so macht es dieser Dienst auch: Er
 * sucht die Storefront-Domains mit genau diesem Host.
 *
 * Eine robots.txt gilt für den ganzen Host. Liegen dort mehrere Kanäle (etwa `/` und `/en`), gilt der Kanal der
 * Domain ohne Pfad; ist auch das nicht eindeutig, `null`, und es bleibt bei der globalen Einstellung.
 */
class HostSalesChannelResolver
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function resolve(string $host): ?string
    {
        $host = mb_strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(domain.sales_channel_id)) AS sales_channel_id, domain.url
               FROM sales_channel_domain domain
               JOIN sales_channel channel ON channel.id = domain.sales_channel_id
              WHERE channel.type_id = :storefront
                AND domain.url LIKE :host',
            [
                'storefront' => hex2bin(Defaults::SALES_CHANNEL_TYPE_STOREFRONT),
                'host' => '%' . $host . '%',
            ]
        );

        return self::pick($rows, $host);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function pick(array $rows, string $host): ?string
    {
        $all = [];
        $root = [];
        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? '');
            $urlHost = parse_url($url, \PHP_URL_HOST);
            $urlPort = parse_url($url, \PHP_URL_PORT);
            $authority = \is_string($urlHost) ? mb_strtolower($urlHost) . (\is_int($urlPort) ? ':' . $urlPort : '') : '';
            // `HTTP_HOST` trägt den Port nur, wenn er nicht der übliche ist; deshalb beide Formen vergleichen.
            if ($authority !== $host && (\is_string($urlHost) ? mb_strtolower($urlHost) : '') !== $host) {
                continue;
            }

            $salesChannelId = (string) $row['sales_channel_id'];
            $all[$salesChannelId] = true;

            $path = trim((string) parse_url($url, \PHP_URL_PATH), '/');
            if ($path === '') {
                $root[$salesChannelId] = true;
            }
        }

        if (\count($root) === 1) {
            return array_key_first($root);
        }

        return \count($all) === 1 ? array_key_first($all) : null;
    }
}
