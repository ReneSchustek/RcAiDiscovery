<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<LlmsDocumentEntity>
 */
final class LlmsDocumentCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return LlmsDocumentEntity::class;
    }
}
