<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Service\Robots;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\Service\Robots\HostSalesChannelResolver;

/**
 * Welcher Verkaufskanal gilt für die robots.txt eines Hosts? Der echte Abruf kennt keinen Kanal; ohne diese
 * Zuordnung galten die KI-Regeln je Kanal nie.
 */
#[CoversClass(HostSalesChannelResolver::class)]
final class HostSalesChannelResolverTest extends TestCase
{
    public function testOneDomainGivesItsChannel(): void
    {
        self::assertSame('aaa', HostSalesChannelResolver::pick([
            ['sales_channel_id' => 'aaa', 'url' => 'https://www.edelstahl-trummer.de'],
        ], 'www.edelstahl-trummer.de'));
    }

    /**
     * Was: Die Suche mit LIKE findet auch fremde Hosts, die den Namen enthalten.
     * Erwartet: Nur der genau passende Host zählt.
     */
    public function testOnlyTheExactHostCounts(): void
    {
        self::assertSame('aaa', HostSalesChannelResolver::pick([
            ['sales_channel_id' => 'aaa', 'url' => 'https://www.edelstahl-trummer.de'],
            ['sales_channel_id' => 'bbb', 'url' => 'https://staging.www.edelstahl-trummer.de'],
        ], 'www.edelstahl-trummer.de'));
    }

    /**
     * Was: Zwei Kanäle auf demselben Host, einer ohne Pfad, einer unter /en.
     * Warum: Eine robots.txt gilt für den ganzen Host.
     * Erwartet: der Kanal der Domain ohne Pfad.
     */
    public function testTheRootDomainWinsOverAPathPrefix(): void
    {
        self::assertSame('aaa', HostSalesChannelResolver::pick([
            ['sales_channel_id' => 'bbb', 'url' => 'https://shop.example/en'],
            ['sales_channel_id' => 'aaa', 'url' => 'https://shop.example/'],
        ], 'shop.example'));
    }

    /**
     * Was: Zwei Kanäle, beide nur unter einem Pfad.
     * Erwartet: nicht eindeutig, also `null` und damit die globale Einstellung.
     */
    public function testAmbiguousHostFallsBackToGlobal(): void
    {
        self::assertNull(HostSalesChannelResolver::pick([
            ['sales_channel_id' => 'aaa', 'url' => 'https://shop.example/de'],
            ['sales_channel_id' => 'bbb', 'url' => 'https://shop.example/en'],
        ], 'shop.example'));
    }

    public function testPortIsMatched(): void
    {
        self::assertSame('aaa', HostSalesChannelResolver::pick([
            ['sales_channel_id' => 'aaa', 'url' => 'http://localhost:8000'],
        ], 'localhost:8000'));
        self::assertNull(HostSalesChannelResolver::pick([], 'shop.example'));
    }
}
