<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Llms;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fehler rund um die gespeicherten llms-Dokumente. Als `HttpException` gibt die Admin-API sie mit
 * Statuscode 404 und einem eigenen Fehlercode weiter statt als allgemeinen Serverfehler.
 */
final class LlmsDocumentException extends HttpException
{
    public const DOCUMENT_NOT_FOUND = 'RC_AI_DISCOVERY__LLMS_DOCUMENT_NOT_FOUND';

    public const DOMAIN_NOT_FOUND = 'RC_AI_DISCOVERY__LLMS_DOMAIN_NOT_FOUND';

    public const CONTENT_MISSING = 'RC_AI_DISCOVERY__LLMS_CONTENT_MISSING';

    public static function documentNotFound(string $documentId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::DOCUMENT_NOT_FOUND,
            'Es gibt kein llms-Dokument mit der ID "{{ documentId }}".',
            ['documentId' => $documentId]
        );
    }

    /**
     * Die Anfrage zum Speichern trägt kein Feld `content` oder keinen Text darin. Ließe man das
     * durch, stünde ein leeres Dokument als „bearbeitet" im Bestand, und keine Generierung fasste es mehr an.
     */
    public static function contentMissing(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CONTENT_MISSING,
            'Zum Speichern fehlt der Inhalt (Feld "content").'
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
