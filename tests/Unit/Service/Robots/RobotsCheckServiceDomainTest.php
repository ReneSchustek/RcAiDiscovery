<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Service\Robots;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\Service\Robots\RobotsCheckService;

/**
 * Welche Domain eines Kanals prüft die Anzeige? Bisher die erste einer unsortierten Liste, also eine zufällige.
 */
final class RobotsCheckServiceDomainTest extends TestCase
{
    public function testRootDomainBeforePathAndAlphabeticalOnTie(): void
    {
        self::assertSame('https://b.example', RobotsCheckService::preferredUrl(['https://a.example/en', 'https://b.example']));
        self::assertSame('https://a.example', RobotsCheckService::preferredUrl(['https://b.example', 'https://a.example']));
        self::assertNull(RobotsCheckService::preferredUrl([]));
    }

    public function testPathOfDomain(): void
    {
        self::assertSame('/', RobotsCheckService::pathOf('https://shop.example'));
        self::assertSame('/', RobotsCheckService::pathOf('https://shop.example/'));
        self::assertSame('/en/', RobotsCheckService::pathOf('https://shop.example/en'));
    }
}
