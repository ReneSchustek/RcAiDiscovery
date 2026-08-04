<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Storefront\Controller;

use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentCollection;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentDefinition;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentEntity;
use Ruhrcoder\RcAiDiscovery\Service\Llms\LlmsDocumentGenerator;
use Shopware\Core\Framework\Adapter\Cache\CacheTagCollector;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert die gespeicherten llms-Dateien als text/plain aus.
 *
 * Der gespeicherte Text ist bereits fertig (absolute Links), deshalb kommt die Route ohne
 * `SalesChannelContext` aus: ein Abruf kostet nur noch einen Datensatz statt der Kategorie-Abfragen.
 *
 * Zur Session: die entsteht trotzdem, aber nicht durch diesen Controller — `StorefrontSubscriber`
 * startet sie für jeden Storefront-Request. Ausgenommen sind allein die Pfade aus der Core-Liste
 * `RequestTransformer::DOES_NOT_REQUIRE_SALESCHANNEL` (dort steht `/robots.txt`, für Plugins nicht
 * erweiterbar). Ein Set-Cookie hält die Antwort `private`, ein HTTP-Cache-Treffer ist damit in
 * Shopware 6.7 für diese Route nicht erreichbar.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID], 'auth_required' => false])]
final class LlmsTxtController
{
    /**
     * @param EntityRepository<LlmsDocumentCollection>      $documentRepository
     * @param EntityRepository<SalesChannelDomainCollection> $domainRepository
     */
    public function __construct(
        private readonly EntityRepository $documentRepository,
        private readonly EntityRepository $domainRepository,
        private readonly LlmsDocumentGenerator $documentGenerator,
        private readonly CacheTagCollector $cacheTagCollector,
    ) {
    }

    #[Route(
        path: '/llms.txt',
        name: 'frontend.rc-ai-discovery.llms',
        defaults: [
            '_format' => 'txt',
            PlatformRequest::ATTRIBUTE_HTTP_CACHE => true,
        ],
        methods: [Request::METHOD_GET]
    )]
    public function llmsTxt(Request $request, Context $context): Response
    {
        return $this->deliver($request, $context, LlmsDocumentDefinition::VARIANT_SHORT);
    }

    #[Route(
        path: '/llms-full.txt',
        name: 'frontend.rc-ai-discovery.llms-full',
        defaults: [
            '_format' => 'txt',
            PlatformRequest::ATTRIBUTE_HTTP_CACHE => true,
        ],
        methods: [Request::METHOD_GET]
    )]
    public function llmsFullTxt(Request $request, Context $context): Response
    {
        return $this->deliver($request, $context, LlmsDocumentDefinition::VARIANT_FULL);
    }

    private function deliver(Request $request, Context $context, string $variant): Response
    {
        $domainId = $request->attributes->get(SalesChannelRequest::ATTRIBUTE_DOMAIN_ID);
        if (!\is_string($domainId) || $domainId === '') {
            return $this->textResponse('');
        }

        $this->cacheTagCollector->addTag(LlmsDocumentDefinition::cacheTag($domainId, $variant));

        $document = $this->findDocument($domainId, $variant, $context)
            ?? $this->generateOnDemand($domainId, $variant, $context);

        return $this->textResponse($document?->getContent() ?? '');
    }

    private function findDocument(string $domainId, string $variant, Context $context): ?LlmsDocumentEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelDomainId', $domainId));
        $criteria->addFilter(new EqualsFilter('variant', $variant));
        $criteria->setLimit(1);

        return $this->documentRepository->search($criteria, $context)->getEntities()->first();
    }

    /**
     * Kaltstart: frisch installiert oder neue Domain — einmalig erzeugen, statt eine leere Datei
     * auszuliefern. Ab dem nächsten Abruf kommt der gespeicherte Stand.
     */
    private function generateOnDemand(string $domainId, string $variant, Context $context): ?LlmsDocumentEntity
    {
        $domain = $this->domainRepository->search(new Criteria([$domainId]), $context)->getEntities()->first();
        if (!$domain instanceof SalesChannelDomainEntity) {
            return null;
        }

        $this->documentGenerator->generateForDomain($domain, $context);

        return $this->findDocument($domainId, $variant, $context);
    }

    private function textResponse(string $content): Response
    {
        $response = new Response($content);
        $response->headers->set('content-type', 'text/plain; charset=utf-8');

        return $response;
    }
}
