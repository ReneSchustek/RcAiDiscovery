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
 * Ermittelt die robots.txt eines Hosts so, wie der Kern sie ausliefert, einschließlich der
 * Vorgaberegeln, die nur in der Twig-Vorlage stehen und in keiner Einstellung.
 *
 * Der Weg ist derselbe wie im `RobotsController` des Kerns: `RobotsPage` laden, dann die Vorlage
 * über den `TemplateFinder` auflösen und rendern. Ein direkter Render der Kern-Vorlage überginge
 * `sw_extends` aus Plugins und Themes, und die Prüfung zeigte dann eine andere Datei als die
 * ausgelieferte.
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
     * Gibt den robots.txt-Text für den Host zurück oder `null`, wenn der Host leer ist.
     *
     * Der Verkaufskanal reist als Request-Attribut mit, weil `RobotsAiRulesSubscriber` seine
     * Einstellungen daran festmacht. Ohne ihn griffe dort nur die globale Konfiguration. Der echte
     * Abruf von `/robots.txt` trägt dieses Attribut nicht: Der Kern löst den Pfad ohne Verkaufskanal
     * auf (`RequestTransformer::DOES_NOT_REQUIRE_SALESCHANNEL`).
     */
    public function render(string $host, Context $context, ?string $salesChannelId = null): ?string
    {
        if (trim($host) === '') {
            return null;
        }

        // `RobotsPageLoader` liest den Host aus `HTTP_HOST` und sucht darüber Domains, Regeln und
        // Sitemaps; ein künstlicher Request mit diesem Wert genügt.
        $request = new Request();
        $request->server->set('HTTP_HOST', $host);
        if ($salesChannelId !== null) {
            $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, $salesChannelId);
        }

        $page = $this->robotsPageLoader->load($request, $context);

        return $this->twig->render($this->templateFinder->find(self::ROBOTS_TEMPLATE), ['page' => $page]);
    }
}
