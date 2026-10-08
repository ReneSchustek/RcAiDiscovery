<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\RcAiDiscovery;
use Shopware\Core\Framework\Plugin;

/**
 * Prüft die Plugin-Klasse, über die Shopware das Paket erkennt und installiert, gegen die
 * Hausregeln. Bricht eine davon, fällt das erst beim Installieren auf: ohne Erbe von `Plugin`
 * findet Shopware kein Plugin, ein abweichender Namensraum passt nicht mehr zum PSR-4-Präfix der
 * composer.json, und ohne `plugin.png` steht das Plugin ohne Symbol in der Verwaltung.
 */
final class RcAiDiscoveryTest extends TestCase
{
    public function testPluginExtendsShopwarePlugin(): void
    {
        $reflection = new \ReflectionClass(RcAiDiscovery::class);
        self::assertTrue($reflection->isSubclassOf(Plugin::class), 'Plugin-Klasse muss von Shopwares Plugin erben');
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
        // Composer vergleicht das PSR-4-Präfix `Ruhrcoder\RcAiDiscovery\` mit Groß- und
        // Kleinschreibung; unter „RuhrCoder" oder ohne Herstellerpräfix fände es die Klasse nicht.
        self::assertSame('Ruhrcoder\\RcAiDiscovery', (new \ReflectionClass(RcAiDiscovery::class))->getNamespaceName());
    }

    public function testPluginIconExists(): void
    {
        $iconPath = __DIR__ . '/../../src/Resources/config/plugin.png';
        self::assertFileExists($iconPath, 'plugin.png muss in src/Resources/config liegen');
    }
}
