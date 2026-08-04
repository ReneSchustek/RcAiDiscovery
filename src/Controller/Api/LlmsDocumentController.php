<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Controller\Api;

use Ruhrcoder\RcAiDiscovery\Service\Llms\LlmsDocumentGenerator;
use Ruhrcoder\RcAiDiscovery\Service\Llms\LlmsDocumentOverview;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API rund um die gespeicherten llms-Dateien: auflisten, neu erzeugen, Inhalt pflegen.
 * Wird von der Komponente in der Plugin-Konfiguration bedient.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
final class LlmsDocumentController
{
    public function __construct(
        private readonly LlmsDocumentOverview $overview,
        private readonly LlmsDocumentGenerator $documentGenerator,
    ) {
    }

    #[Route(
        path: '/api/_action/rc-ai-discovery/llms-documents',
        name: 'api.action.rc_ai_discovery.llms_documents.list',
        defaults: ['_acl' => ['sales_channel:read']],
        methods: ['GET']
    )]
    public function list(Context $context): JsonResponse
    {
        return new JsonResponse(['documents' => $this->overview->load($context)]);
    }

    #[Route(
        path: '/api/_action/rc-ai-discovery/llms-documents/refresh',
        name: 'api.action.rc_ai_discovery.llms_documents.refresh',
        defaults: ['_acl' => ['sales_channel:update']],
        methods: ['POST']
    )]
    public function refresh(Context $context): JsonResponse
    {
        $written = $this->documentGenerator->generateAll($context);

        return new JsonResponse(['written' => $written, 'documents' => $this->overview->load($context)]);
    }

    #[Route(
        path: '/api/_action/rc-ai-discovery/llms-documents/{documentId}/content',
        name: 'api.action.rc_ai_discovery.llms_documents.save',
        defaults: ['_acl' => ['sales_channel:update']],
        methods: ['PATCH']
    )]
    public function save(string $documentId, Request $request, Context $context): JsonResponse
    {
        $content = $request->request->get('content');
        $this->documentGenerator->saveCustomContent($documentId, \is_string($content) ? $content : '', $context);

        return new JsonResponse(['documents' => $this->overview->load($context)]);
    }

    #[Route(
        path: '/api/_action/rc-ai-discovery/llms-documents/{documentId}/regenerate',
        name: 'api.action.rc_ai_discovery.llms_documents.regenerate',
        defaults: ['_acl' => ['sales_channel:update']],
        methods: ['POST']
    )]
    public function regenerate(string $documentId, Context $context): JsonResponse
    {
        $this->documentGenerator->regenerate($documentId, $context);

        return new JsonResponse(['documents' => $this->overview->load($context)]);
    }
}
