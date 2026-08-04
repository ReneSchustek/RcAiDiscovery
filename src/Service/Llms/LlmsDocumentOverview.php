<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Llms;

use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Stellt die gespeicherten Dokumente für die Admin-Anzeige zusammen: Domain, Variante, Stand,
 * Zustand und Inhalt — bewusst als schlanke Liste statt als DAL-Rohdaten.
 */
final class LlmsDocumentOverview
{
    /**
     * @param EntityRepository<LlmsDocumentCollection> $documentRepository
     */
    public function __construct(private readonly EntityRepository $documentRepository)
    {
    }

    /**
     * @return list<array{id: string, url: string, variant: string, isCustom: bool, generatedAt: string, content: string}>
     */
    public function load(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('salesChannelDomain');
        $criteria->addSorting(new FieldSorting('salesChannelDomainId'), new FieldSorting('variant'));

        $documents = [];
        foreach ($this->documentRepository->search($criteria, $context)->getEntities() as $document) {
            $documents[] = [
                'id' => $document->getId(),
                'url' => $document->getSalesChannelDomain()?->getUrl() ?? '',
                'variant' => $document->getVariant(),
                'isCustom' => $document->isCustom(),
                'generatedAt' => $document->getGeneratedAt()->format(\DateTimeInterface::ATOM),
                'content' => $document->getContent(),
            ];
        }

        return $documents;
    }
}
