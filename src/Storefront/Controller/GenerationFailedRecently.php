<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Storefront\Controller;

/**
 * Die Erzeugung der llms.txt für diese Domain ist vor weniger als fünf Minuten gescheitert; bis dahin wird sie
 * nicht erneut versucht. Nur intern zwischen `generateOnDemand()` und `deliver()`.
 */
final class GenerationFailedRecently extends \RuntimeException
{
}
