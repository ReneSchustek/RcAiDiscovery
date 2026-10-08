<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Llms;

use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Stellt die gespeicherten Dokumente für die Anzeige in der Verwaltung zusammen: Domain, Variante,
 * Stand, Zustand und Inhalt. Die Komponente bekommt eine flache Liste, damit sie weder die
 * Entitätsstruktur noch die Domain-Zuordnung kennen muss.
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
        // Nach Kennung statt Adresse sortiert: Es reicht, dass beide Fassungen einer Domain
        // nebeneinander stehen, `full` vor `short`.
        $criteria->addSorting(new FieldSorting('salesChannelDomainId'), new FieldSorting('variant'));

        $documents = [];
        foreach ($this->documentRepository->search($criteria, $context)->getEntities() as $document) {
            $documents[] = [
                'id' => $document->getId(),
                // Ohne geladene Domain bleibt die Adresse leer; die Zeile zeigt das Dokument trotzdem.
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
