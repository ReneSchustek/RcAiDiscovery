<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Service\Llms;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentCollection;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentDefinition;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentEntity;
use Ruhrcoder\RcAiDiscovery\Service\Llms\LlmsDocumentGenerator;
use Ruhrcoder\RcAiDiscovery\Service\LlmsTxtConfigProvider;
use Ruhrcoder\RcAiDiscovery\Service\LlmsTxtGenerator;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinderInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Twig\Environment;

final class LlmsDocumentGeneratorTest extends TestCase
{
    private const DOMAIN_ID = '0191aaaabbbbccccddddeeeeffff0001';

    private const SALES_CHANNEL_ID = '0191aaaabbbbccccddddeeeeffff0002';

    /**
     * @var list<array<string, mixed>>
     */
    private array $upserted = [];

    /**
     * @var list<string>
     */
    private array $invalidatedTags = [];

    protected function setUp(): void
    {
        $this->upserted = [];
        $this->invalidatedTags = [];
    }

    public function testWritesBothVariantsForADomain(): void
    {
        $generator = $this->createGenerator([]);

        $written = $generator->generateForDomain($this->domain(), Context::createDefaultContext());

        self::assertSame(2, $written);
        self::assertSame(
            [LlmsDocumentDefinition::VARIANT_SHORT, LlmsDocumentDefinition::VARIANT_FULL],
            array_column($this->upserted, 'variant')
        );
        foreach ($this->upserted as $payload) {
            self::assertFalse($payload['isCustom']);
            self::assertSame(self::DOMAIN_ID, $payload['salesChannelDomainId']);
            self::assertStringContainsString('# Testshop', $payload['content']);
        }
    }

    /**
     * Redaktionell bearbeitete Dateien darf die geplante Generierung nicht überschreiben.
     */
    public function testSkipsEditedDocument(): void
    {
        $generator = $this->createGenerator([
            LlmsDocumentDefinition::VARIANT_FULL => $this->document(LlmsDocumentDefinition::VARIANT_FULL, true),
        ]);

        $written = $generator->generateForDomain($this->domain(), Context::createDefaultContext());

        self::assertSame(1, $written);
        self::assertSame([LlmsDocumentDefinition::VARIANT_SHORT], array_column($this->upserted, 'variant'));
    }

    public function testForceOverwritesEditedDocumentAndResetsFlag(): void
    {
        $existing = $this->document(LlmsDocumentDefinition::VARIANT_FULL, true);
        $generator = $this->createGenerator([LlmsDocumentDefinition::VARIANT_FULL => $existing]);

        $written = $generator->generateForDomain($this->domain(), Context::createDefaultContext(), true);

        self::assertSame(2, $written);
        $full = $this->payloadOf(LlmsDocumentDefinition::VARIANT_FULL);
        self::assertFalse($full['isCustom']);
        self::assertSame($existing->getId(), $full['id'], 'Das bestehende Dokument wird aktualisiert, nicht dupliziert.');
    }

    public function testUnchangedVariantKeepsItsDocumentId(): void
    {
        $existing = $this->document(LlmsDocumentDefinition::VARIANT_SHORT, false);
        $generator = $this->createGenerator([LlmsDocumentDefinition::VARIANT_SHORT => $existing]);

        $generator->generateForDomain($this->domain(), Context::createDefaultContext());

        self::assertSame($existing->getId(), $this->payloadOf(LlmsDocumentDefinition::VARIANT_SHORT)['id']);
    }

    public function testCacheIsInvalidatedForWrittenVariants(): void
    {
        $generator = $this->createGenerator([]);

        $generator->generateForDomain($this->domain(), Context::createDefaultContext());

        self::assertSame(
            [
                LlmsDocumentDefinition::cacheTag(self::DOMAIN_ID, LlmsDocumentDefinition::VARIANT_SHORT),
                LlmsDocumentDefinition::cacheTag(self::DOMAIN_ID, LlmsDocumentDefinition::VARIANT_FULL),
            ],
            $this->invalidatedTags
        );
    }

    /**
     * Eine kaputte Domain darf die übrigen nicht mit ausfallen lassen.
     */
    public function testFailingDomainDoesNotStopTheOthers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $failing = $this->domain('0191aaaabbbbccccddddeeeeffff0003', 'https://kaputt.example');
        $generator = $this->createGenerator([], [$failing, $this->domain()], $logger, $failing->getSalesChannelId());

        $written = $generator->generateAll(Context::createDefaultContext());

        self::assertSame(2, $written, 'Die zweite Domain wird trotz Fehler der ersten geschrieben.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(string $variant): array
    {
        foreach ($this->upserted as $payload) {
            if ($payload['variant'] === $variant) {
                return $payload;
            }
        }

        self::fail('Variante nicht geschrieben: ' . $variant);
    }

    /**
     * @param array<string, LlmsDocumentEntity> $existingDocuments   Variante => Dokument
     * @param list<SalesChannelDomainEntity>    $domains
     * @param string|null                       $failingSalesChannel Sales-Channel, dessen Kontext-Aufbau scheitert
     */
    private function createGenerator(
        array $existingDocuments,
        array $domains = [],
        ?LoggerInterface $logger = null,
        ?string $failingSalesChannel = null,
    ): LlmsDocumentGenerator {
        return new LlmsDocumentGenerator(
            $this->domainRepository($domains),
            $this->documentRepository($existingDocuments),
            $this->contextFactory($failingSalesChannel),
            $this->llmsTxtGenerator(),
            new LlmsTxtConfigProvider($this->emptySystemConfig()),
            $this->seoUrlReplacer(),
            $this->templateFinder(),
            $this->twig(),
            $this->cacheInvalidator(),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * @param list<SalesChannelDomainEntity> $domains
     *
     * @return EntityRepository<SalesChannelDomainCollection>
     */
    private function domainRepository(array $domains): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(
            $this->searchResult(new SalesChannelDomainCollection($domains))
        );

        return $repository;
    }

    /**
     * @param array<string, LlmsDocumentEntity> $existingDocuments
     *
     * @return EntityRepository<LlmsDocumentCollection>
     */
    private function documentRepository(array $existingDocuments): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(
            $this->searchResult(new LlmsDocumentCollection(array_values($existingDocuments)))
        );
        $writtenEvent = $this->createMock(EntityWrittenContainerEvent::class);
        $repository->method('upsert')->willReturnCallback(
            function (array $payload) use ($writtenEvent): EntityWrittenContainerEvent {
                foreach ($payload as $entry) {
                    $this->upserted[] = $entry;
                }

                return $writtenEvent;
            }
        );

        return $repository;
    }

    private function contextFactory(?string $failingSalesChannel): AbstractSalesChannelContextFactory
    {
        $factory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $factory->method('create')->willReturnCallback(
            function (string $token, string $salesChannelId) use ($failingSalesChannel): SalesChannelContext {
                if ($salesChannelId === $failingSalesChannel) {
                    throw new \RuntimeException('Kontext nicht aufbaubar');
                }

                return $this->salesChannelContext();
            }
        );

        return $factory;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(self::SALES_CHANNEL_ID);
        $salesChannel->setName('Testshop');
        $salesChannel->setNavigationCategoryId('0191aaaabbbbccccddddeeeeffff0009');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        return $context;
    }

    private function llmsTxtGenerator(): LlmsTxtGenerator
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')->willReturn('Testshop');

        $categoryRepository = $this->createMock(SalesChannelRepository::class);
        $categoryRepository->method('search')->willReturn($this->searchResult(new CategoryCollection()));

        return new LlmsTxtGenerator($systemConfig, $categoryRepository, $this->seoUrlReplacer());
    }

    private function emptySystemConfig(): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')->willReturn('');

        return $systemConfig;
    }

    private function seoUrlReplacer(): SeoUrlPlaceholderHandlerInterface
    {
        $replacer = $this->createMock(SeoUrlPlaceholderHandlerInterface::class);
        $replacer->method('generate')->willReturn('URL::x');
        $replacer->method('replace')->willReturnArgument(0);

        return $replacer;
    }

    private function templateFinder(): TemplateFinderInterface
    {
        $finder = $this->createMock(TemplateFinderInterface::class);
        $finder->method('find')->willReturnArgument(0);

        return $finder;
    }

    private function twig(): Environment
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(
            static fn (string $template, array $context = []): string => (string) ($context['llmsContent'] ?? '')
        );

        return $twig;
    }

    private function cacheInvalidator(): CacheInvalidator
    {
        $invalidator = $this->createMock(CacheInvalidator::class);
        $invalidator->method('invalidate')->willReturnCallback(
            function (array $tags): void {
                foreach ($tags as $tag) {
                    $this->invalidatedTags[] = $tag;
                }
            }
        );

        return $invalidator;
    }

    private function domain(string $id = self::DOMAIN_ID, string $url = 'https://shop.example'): SalesChannelDomainEntity
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId($id);
        $domain->setUrl($url);
        $domain->setSalesChannelId($id === self::DOMAIN_ID ? self::SALES_CHANNEL_ID : $id);
        $domain->setLanguageId('0191aaaabbbbccccddddeeeeffff0004');
        $domain->setCurrencyId('0191aaaabbbbccccddddeeeeffff0005');

        return $domain;
    }

    private function document(string $variant, bool $isCustom): LlmsDocumentEntity
    {
        $document = new LlmsDocumentEntity();
        $document->setId('0191aaaabbbbccccddddeeeeffff001' . ($variant === LlmsDocumentDefinition::VARIANT_FULL ? '1' : '2'));
        $document->setSalesChannelDomainId(self::DOMAIN_ID);
        $document->setVariant($variant);
        $document->setContent('alter Inhalt');
        $document->setIsCustom($isCustom);
        $document->setGeneratedAt(new \DateTimeImmutable('2026-07-01 10:00:00'));

        return $document;
    }

    /**
     * @param EntityCollection<covariant \Shopware\Core\Framework\DataAbstractionLayer\Entity> $collection
     *
     * @return EntitySearchResult<EntityCollection<covariant \Shopware\Core\Framework\DataAbstractionLayer\Entity>>
     */
    private function searchResult(EntityCollection $collection): EntitySearchResult
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        return $result;
    }
}
