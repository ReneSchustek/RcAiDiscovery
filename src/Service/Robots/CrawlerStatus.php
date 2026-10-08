<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

/**
 * Ergebnis für einen KI-Crawler gegen die robots.txt eines Verkaufskanals.
 *
 * Grund und Hinweis kommen als sprachneutrale Codes, weil die Verwaltung sie über ihre Textbausteine
 * in die Sprache des Benutzers übersetzt.
 */
final class CrawlerStatus implements \JsonSerializable
{
    public const ALLOWED = 'allowed';

    public const BLOCKED = 'blocked';

    public const UNKNOWN = 'unknown';

    /**
     * Kein Block passt, nicht einmal `*`; ohne Aussage gilt ein Crawler als erlaubt.
     */
    public const REASON_ALLOWED_DEFAULT = 'allowed_default';

    public const REASON_ALLOWED_OWN = 'allowed_own';

    public const REASON_ALLOWED_WILDCARD = 'allowed_wildcard';

    public const REASON_BLOCKED_OWN = 'blocked_own';

    public const REASON_BLOCKED_WILDCARD = 'blocked_wildcard';

    // Mit diesen vier Gründen steht der Status auf `UNKNOWN`, weil die Prüfung nicht bis zur
    // Auswertung kam.
    public const REASON_NO_DOMAIN = 'no_domain';

    public const REASON_HOST_UNREADABLE = 'host_unreadable';

    public const REASON_ROBOTS_UNAVAILABLE = 'robots_unavailable';

    public const REASON_CHECK_FAILED = 'check_failed';

    /**
     * `$status` ist eine der Konstanten `ALLOWED`, `BLOCKED`, `UNKNOWN`, `$reasonCode` eine der
     * `REASON_*`-Konstanten.
     *
     * @param string      $group   Gruppe aus dem Katalog (Suche, Abruf, Training)
     * @param string|null $noteCode zusätzlicher Hinweis für die Anzeige, ebenfalls sprachneutral
     */
    public function __construct(
        public readonly string $token,
        public readonly string $status,
        public readonly string $reasonCode,
        public readonly string $group,
        public readonly ?string $noteCode = null,
    ) {
    }

    /**
     * @return array{token: string, status: string, reasonCode: string, group: string, noteCode: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'token' => $this->token,
            'status' => $this->status,
            'reasonCode' => $this->reasonCode,
            'group' => $this->group,
            'noteCode' => $this->noteCode,
        ];
    }
}
