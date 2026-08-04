<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

use Shopware\Storefront\Page\Robots\Struct\RobotsDirective;

/**
 * Ergebnis der User-Agent-Auflösung: die anwendbaren Pfad-Direktiven für einen Crawler und ob sie
 * aus einem eigenen (exakt passenden) Block oder aus dem „*"-Sammelblock stammen.
 *
 * @internal
 */
final class DirectiveResolution
{
    /**
     * @param list<RobotsDirective> $directives
     */
    public function __construct(
        public readonly array $directives,
        public readonly bool $viaOwnBlock,
        public readonly bool $hasBlock,
    ) {
    }
}
