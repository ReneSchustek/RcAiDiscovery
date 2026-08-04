<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

/**
 * Ein KI-Crawler im Katalog: sein robots.txt-Token, sein Zweck und ob das Plugin für ihn
 * eine Regel schreiben darf.
 */
final class AiCrawler
{
    /**
     * @param string      $group      eine der AiCrawlerCatalog::GROUP_*-Konstanten
     * @param bool        $writesRule false = nur auswerten, nie eine Regel schreiben
     * @param string|null $noteCode   sprachneutraler Hinweis-Code für die Admin-Anzeige
     */
    public function __construct(
        public readonly string $token,
        public readonly string $group,
        public readonly bool $writesRule = true,
        public readonly ?string $noteCode = null,
    ) {
    }
}
