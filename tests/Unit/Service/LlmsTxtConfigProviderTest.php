<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\Service\LlmsTxtConfigProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class LlmsTxtConfigProviderTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    public function testUnsetFieldsResultInAutoConfig(): void
    {
        $config = $this->createProvider([])->load(self::SALES_CHANNEL_ID);

        self::assertNull($config->title);
        self::assertNull($config->summary);
        self::assertNull($config->additionalContent);
    }

    public function testWhitespaceOnlyValuesCountAsUnset(): void
    {
        $config = $this->createProvider([
            LlmsTxtConfigProvider::KEY_TITLE => '   ',
            LlmsTxtConfigProvider::KEY_SUMMARY => "\n\t ",
            LlmsTxtConfigProvider::KEY_ADDITIONAL_CONTENT => "  \r\n  ",
        ])->load(self::SALES_CHANNEL_ID);

        self::assertNull($config->title);
        self::assertNull($config->summary);
        self::assertNull($config->additionalContent);
    }

    public function testTitleAndSummaryAreReducedToOneLine(): void
    {
        $config = $this->createProvider([
            LlmsTxtConfigProvider::KEY_TITLE => "  Ruhrcoder\nShop  ",
            LlmsTxtConfigProvider::KEY_SUMMARY => "Erste Zeile\r\nZweite   Zeile",
        ])->load(self::SALES_CHANNEL_ID);

        self::assertSame('Ruhrcoder Shop', $config->title);
        self::assertSame('Erste Zeile Zweite Zeile', $config->summary);
    }

    public function testAdditionalContentKeepsMarkdownStructureWithNormalizedLineEndings(): void
    {
        $config = $this->createProvider([
            LlmsTxtConfigProvider::KEY_ADDITIONAL_CONTENT => "\r\n## Kontakt\r\n\r\n- [Hilfe](/hilfe)\r\n\r\n",
        ])->load(self::SALES_CHANNEL_ID);

        self::assertSame("## Kontakt\n\n- [Hilfe](/hilfe)", $config->additionalContent);
    }

    /**
     * @param array<string, string> $values
     */
    private function createProvider(array $values): LlmsTxtConfigProvider
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')->willReturnCallback(
            static fn (string $key, ?string $salesChannelId = null): string => $values[$key] ?? ''
        );

        return new LlmsTxtConfigProvider($systemConfig);
    }
}
