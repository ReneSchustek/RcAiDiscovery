<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery;

use Shopware\Core\Framework\Plugin;

/**
 * Einstieg des Plugins für KI-Auffindbarkeit: `/llms.txt` und `/llms-full.txt` je Domain sowie
 * Prüfung und Ergänzung der KI-Crawler-Regeln in der robots.txt.
 *
 * Die Klasse bleibt leer. Es gibt keine Installationsschritte jenseits der Migration, und die
 * Dienste stehen in `Resources/config/services.xml`.
 */
final class RcAiDiscovery extends Plugin
{
}
