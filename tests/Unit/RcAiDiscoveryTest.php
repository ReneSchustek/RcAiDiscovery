<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\RcAiDiscovery;
use Shopware\Core\Framework\Plugin;

/**
 * Plugin-Bootstrap-Eigenschaften, die für Shopware-Lifecycle und CI-Discovery zwingend sind.
 */
final class RcAiDiscoveryTest extends TestCase
{
    public function testPluginExtendsShopwarePlugin(): void
    {
        self::assertTrue(is_subclass_of(RcAiDiscovery::class, Plugin::class));
    }

    public function testPluginClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(RcAiDiscovery::class);
        self::assertTrue($reflection->isFinal(), 'Plugin-Klasse muss final sein (Vererbung in Plugins unerwünscht)');
    }

    public function testPluginClassHasStrictTypesEnabled(): void
    {
        $reflection = new \ReflectionClass(RcAiDiscovery::class);
        $file = (string) $reflection->getFileName();
        $contents = file_get_contents($file);
        self::assertNotFalse($contents);
        self::assertStringContainsString('declare(strict_types=1);', $contents);
    }

    public function testPluginNamespaceFollowsRuhrcoderConvention(): void
    {
        // Pflicht: Ruhrcoder\Rc{PluginName}\ — niemals RuhrCoder (falsches C) oder ohne Vendor-Präfix.
        self::assertSame('Ruhrcoder\\RcAiDiscovery', (new \ReflectionClass(RcAiDiscovery::class))->getNamespaceName());
    }

    public function testPluginIconExists(): void
    {
        $iconPath = __DIR__ . '/../../src/Resources/config/plugin.png';
        self::assertFileExists($iconPath, 'plugin.png muss in src/Resources/config liegen');
    }
}
