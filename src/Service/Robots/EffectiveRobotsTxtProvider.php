<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinderInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Page\Robots\RobotsPageLoader;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * Ermittelt die tatsächlich ausgelieferte robots.txt eines Hosts — inklusive der Core-Defaults,
 * die nur im Twig-Template stehen (nicht in der Config). Es wird exakt das gerendert, was der
 * Core-RobotsController ausliefert: RobotsPage laden und das über den TemplateFinder aufgelöste
 * Template rendern. Der TemplateFinder (statt eines rohen Renders) berücksichtigt `sw_extends`-
 * Überschreibungen von Plugins/Themes — nur so bleibt der Check deckungsgleich mit der Realität.
 */
final class EffectiveRobotsTxtProvider
{
    private const ROBOTS_TEMPLATE = '@Storefront/storefront/page/robots/robots.txt.twig';

    public function __construct(
        private readonly RobotsPageLoader $robotsPageLoader,
        private readonly TemplateFinderInterface $templateFinder,
        private readonly Environment $twig,
    ) {
    }

    /**
     * Gibt den robots.txt-Text für den Host zurück oder null, wenn kein Host vorliegt.
     *
     * Der Sales-Channel wird als Request-Attribut mitgegeben, damit kanal-spezifische Regeln
     * (auch die des eigenen KI-Regel-Subscribers) genauso greifen wie im echten Storefront-Abruf.
     */
    public function render(string $host, Context $context, ?string $salesChannelId = null): ?string
    {
        if (trim($host) === '') {
            return null;
        }

        // RobotsPageLoader liest den Host aus HTTP_HOST und ermittelt darüber die Domain-Regeln.
        $request = new Request();
        $request->server->set('HTTP_HOST', $host);
        if ($salesChannelId !== null) {
            $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, $salesChannelId);
        }

        $page = $this->robotsPageLoader->load($request, $context);

        return $this->twig->render($this->templateFinder->find(self::ROBOTS_TEMPLATE), ['page' => $page]);
    }
}
