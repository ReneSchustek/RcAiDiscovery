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
 * Aufrufer ist die Komponente `rc-ai-discovery-llms-documents` in der Plugin-Konfiguration.
 *
 * Jede Aktion antwortet mit der vollständigen, frischen Liste. Die Komponente ersetzt damit ihren
 * Stand in einem Zug und braucht keinen zweiten Abruf. Gelesen wird mit `sales_channel:read`,
 * geschrieben mit `sales_channel:update`: Die Dateien hängen an den Domains eines Verkaufskanals,
 * ein eigenes Privileg brächte nur eine weitere Rolle zum Pflegen.
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
        // Der JSON-Rumpf liegt nach Shopwares Request-Umwandlung in `request`. Fehlt `content` oder
        // ist es kein Text, wird ein leerer Inhalt gespeichert.
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
