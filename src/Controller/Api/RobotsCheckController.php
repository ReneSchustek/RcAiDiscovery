<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Controller\Api;

use Ruhrcoder\RcAiDiscovery\Service\Robots\RobotsCheckService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API für die Statusanzeige `rc-ai-discovery-robots-status`: je aktivem Storefront-Kanal,
 * welche KI-Crawler die robots.txt zulässt.
 *
 * Die Prüfung liest nur, deshalb genügt das Kern-Privileg `sales_channel:read`.
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
