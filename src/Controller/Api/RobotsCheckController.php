<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Controller\Api;

use Ruhrcoder\RcAiDiscovery\Service\Robots\RobotsCheckService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API: liefert den robots.txt-KI-Crawler-Status aller aktiven Storefront-Sales-Channels.
 * Nur Lesezugriff; wird von der Admin-Statusanzeige (config.xml-Komponente) aufgerufen.
 * ACL: Kern-Privileg `sales_channel:read` (Least Privilege).
 */
#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['sales_channel:read']])]
final class RobotsCheckController
{
    public function __construct(private readonly RobotsCheckService $checkService)
    {
    }

    #[Route(
        path: '/api/_action/rc-ai-discovery/robots-check',
        name: 'api.action.rc_ai_discovery.robots_check',
        methods: ['GET']
    )]
    public function check(Context $context): JsonResponse
    {
        return new JsonResponse([
            'salesChannels' => $this->checkService->checkAllStorefronts($context),
        ]);
    }
}
