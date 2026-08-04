<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Llms;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fehler rund um die gespeicherten llms-Dokumente. Als HttpException, damit die Admin-API
 * einen sprechenden Fehlercode und den passenden Statuscode liefert.
 */
final class LlmsDocumentException extends HttpException
{
    public const DOCUMENT_NOT_FOUND = 'RC_AI_DISCOVERY__LLMS_DOCUMENT_NOT_FOUND';

    public const DOMAIN_NOT_FOUND = 'RC_AI_DISCOVERY__LLMS_DOMAIN_NOT_FOUND';

    public static function documentNotFound(string $documentId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::DOCUMENT_NOT_FOUND,
            'Es gibt kein llms-Dokument mit der ID "{{ documentId }}".',
            ['documentId' => $documentId]
        );
    }

    public static function domainNotFound(string $domainId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::DOMAIN_NOT_FOUND,
            'Zum Dokument gibt es keine Domain mit der ID "{{ domainId }}" mehr.',
            ['domainId' => $domainId]
        );
    }
}
