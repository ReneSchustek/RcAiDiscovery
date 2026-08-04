<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Robots;

/**
 * Status eines einzelnen KI-Crawlers gegen die robots.txt eines Sales-Channels.
 *
 * Der Grund wird als sprachneutraler Code geliefert (kein fertiger Satz), damit die
 * Admin-Oberfläche ihn übersetzen kann (i18n).
 */
final class CrawlerStatus implements \JsonSerializable
{
    public const ALLOWED = 'allowed';

    public const BLOCKED = 'blocked';

    public const UNKNOWN = 'unknown';

    // Grund-Codes (werden im Admin per Snippet übersetzt).
    public const REASON_ALLOWED_DEFAULT = 'allowed_default';

    public const REASON_ALLOWED_OWN = 'allowed_own';

    public const REASON_ALLOWED_WILDCARD = 'allowed_wildcard';

    public const REASON_BLOCKED_OWN = 'blocked_own';

    public const REASON_BLOCKED_WILDCARD = 'blocked_wildcard';

    public const REASON_NO_DOMAIN = 'no_domain';

    public const REASON_HOST_UNREADABLE = 'host_unreadable';

    public const REASON_ROBOTS_UNAVAILABLE = 'robots_unavailable';

    public const REASON_CHECK_FAILED = 'check_failed';

    /**
     * @param string      $group    Gruppe aus dem Katalog (Suche, Abruf, Training)
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
