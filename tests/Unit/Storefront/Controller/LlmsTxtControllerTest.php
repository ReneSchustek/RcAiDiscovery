<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentCollection;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentDefinition;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentEntity;
use Ruhrcoder\RcAiDiscovery\Service\Llms\LlmsDocumentGenerator;
use Ruhrcoder\RcAiDiscovery\Storefront\Controller\LlmsTxtController;
use Shopware\Core\Framework\Adapter\Cache\CacheTagCollector;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Symfony\Component\HttpFoundation\Request;

final class LlmsTxtControllerTest extends TestCase
{
    private const DOMAIN_ID = '0191aaaabbbbccccddddeeeeffff0001';

    public function testDeliversStoredDocumentAsPlainText(): void
    {
        $controller = $this->controller([$this->document("# Gespeichert\n")]);

        $response = $controller->llmsTxt($this->request(), Context::createDefaultContext());

        self::assertSame("# Gespeichert\n", $response->getContent());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('content-type'));
    }

    /**
     * Kaltstart: fehlt das Dokument, wird es einmalig erzeugt statt eine leere Datei auszuliefern.
     */
    public function testGeneratesOnDemandWhenDocumentIsMissing(): void
    {
        $generator = $this->createMock(LlmsDocumentGenerator::class);
        $generator->expects(self::once())->method('generateForDomain');

        $documentRepository = $this->createMock(EntityRepository::class);
        $documentRepository->method('search')->willReturnOnConsecutiveCalls(
            $this->searchResult(new LlmsDocumentCollection()),
            $this->searchResult(new LlmsDocumentCollection([$this->document("# Frisch erzeugt\n")]))
        );

        $controller = new LlmsTxtController(
            $documentRepository,
            $this->domainRepository(),
            $generator,
            $this->createMock(CacheTagCollector::class)
        );

        $response = $controller->llmsTxt($this->request(), Context::createDefaultContext());

        self::assertSame("# Frisch erzeugt\n", $response->getContent());
    }

    public function testEmptyResponseWithoutDomainAttribute(): void
    {
        $controller = $this->controller([$this->document("# Gespeichert\n")]);

        $response = $controller->llmsTxt(new Request(), Context::createDefaultContext());

        self::assertSame('', $response->getContent());
    }

    public function testFullVariantIsRequested(): void
    {
        $collector = $this->createMock(CacheTagCollector::class);
        $collector->expects(self::once())
            ->method('addTag')
            ->with(LlmsDocumentDefinition::cacheTag(self::DOMAIN_ID, LlmsDocumentDefinition::VARIANT_FULL));

        $controller = new LlmsTxtController(
            $this->documentRepository([$this->document("# Lang\n", LlmsDocumentDefinition::VARIANT_FULL)]),
            $this->domainRepository(),
            $this->createMock(LlmsDocumentGenerator::class),
            $collector
        );

        self::assertSame("# Lang\n", $controller->llmsFullTxt($this->request(), Context::createDefaultContext())->getContent());
    }

    /**
     * @param list<LlmsDocumentEntity> $documents
     */
    private function controller(array $documents): LlmsTxtController
    {
        return new LlmsTxtController(
            $this->documentRepository($documents),
            $this->domainRepository(),
            $this->createMock(LlmsDocumentGenerator::class),
            $this->createMock(CacheTagCollector::class)
        );
    }

    /**
     * @param list<LlmsDocumentEntity> $documents
     *
     * @return EntityRepository<LlmsDocumentCollection>
     */
    private function documentRepository(array $documents): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->searchResult(new LlmsDocumentCollection($documents)));

        return $repository;
    }

    /**
     * @return EntityRepository<SalesChannelDomainCollection>
     */
    private function domainRepository(): EntityRepository
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId(self::DOMAIN_ID);
        $domain->setUrl('https://shop.example');
        $domain->setSalesChannelId('0191aaaabbbbccccddddeeeeffff0002');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->searchResult(new SalesChannelDomainCollection([$domain])));

        return $repository;
    }

    private function request(): Request
    {
        $request = new Request();
        $request->attributes->set(SalesChannelRequest::ATTRIBUTE_DOMAIN_ID, self::DOMAIN_ID);

        return $request;
    }

    private function document(string $content, string $variant = LlmsDocumentDefinition::VARIANT_SHORT): LlmsDocumentEntity
    {
        $document = new LlmsDocumentEntity();
        $document->setId('0191aaaabbbbccccddddeeeeffff0003');
        $document->setSalesChannelDomainId(self::DOMAIN_ID);
        $document->setVariant($variant);
        $document->setContent($content);
        $document->setIsCustom(false);
        $document->setGeneratedAt(new \DateTimeImmutable());

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
