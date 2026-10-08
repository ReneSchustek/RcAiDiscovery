<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Service\Llms;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentCollection;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentDefinition;
use Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument\LlmsDocumentEntity;
use Ruhrcoder\RcAiDiscovery\Service\LlmsTxtConfigProvider;
use Ruhrcoder\RcAiDiscovery\Service\LlmsTxtGenerator;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinderInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Twig\Environment;

/**
 * Erzeugt die llms-Dateien und speichert sie je Sales-Channel-Domain ab.
 *
 * Der gespeicherte Text ist auslieferungsfertig, die SEO-Platzhalter sind schon durch absolute
 * Adressen ersetzt. Die Storefront-Route liest deshalb nur einen Datensatz und braucht weder einen
 * Verkaufskanal-Kontext noch die Kategorie-Abfragen. Den Kontext baut nur die Generierung selbst.
 *
 * Die Klasse ist nicht `final`, weil Storefront-Route und Admin-API sie nutzen und ihre Tests sie
 * ersetzen müssen. Ohne Schnittstelle ist sie nur so auch dekorierbar.
 */
class LlmsDocumentGenerator
{
    /**
     * Wird über den `TemplateFinder` aufgelöst, damit ein Theme den Block der Vorlage erweitern kann.
     */
    private const TEMPLATE = '@RcAiDiscovery/storefront/page/llms/llms.txt.twig';

    /**
     * @param EntityRepository<SalesChannelDomainCollection> $domainRepository
     * @param EntityRepository<LlmsDocumentCollection>       $documentRepository
     */
    public function __construct(
        private readonly EntityRepository $domainRepository,
        private readonly EntityRepository $documentRepository,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly LlmsTxtGenerator $generator,
        private readonly LlmsTxtConfigProvider $configProvider,
        private readonly SeoUrlPlaceholderHandlerInterface $seoUrlReplacer,
        private readonly TemplateFinderInterface $templateFinder,
        private readonly Environment $twig,
        private readonly CacheInvalidator $cacheInvalidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Erzeugt die Dateien aller Domains aktiver Storefront-Kanäle. Scheitert eine Domain, wird das
     * protokolliert und die nächste bearbeitet.
     *
     * @param bool $force true = auch in der Verwaltung bearbeitete Dokumente überschreiben
     *
     * @return int Anzahl geschriebener Dokumente
     */
    public function generateAll(Context $context, bool $force = false): int
    {
        $written = 0;
        foreach ($this->loadActiveDomains($context) as $domain) {
            try {
                $written += $this->generateForDomain($domain, $context, $force);
            } catch (\Throwable $exception) {
                // Eine kaputte Domain darf die übrigen Dateien nicht mit ausfallen lassen.
                $this->logger->error('llms-Datei konnte für eine Domain nicht erzeugt werden', [
                    'salesChannelDomainId' => $domain->getId(),
                    'url' => $domain->getUrl(),
                    'exception' => $exception,
                ]);
            }
        }

        return $written;
    }

    /**
     * Schreibt Kurz- und Langfassung einer Domain. Mit `$force` werden auch bearbeitete Dokumente
     * überschrieben, und zwar beide Fassungen. Fehler fängt die Methode nicht ab; das übernimmt
     * `generateAll()`, für den Einzelaufruf der Aufrufer.
     *
     * @return int Anzahl geschriebener Dokumente dieser Domain
     */
    public function generateForDomain(SalesChannelDomainEntity $domain, Context $context, bool $force = false): int
    {
        $existing = $this->loadDocumentsOfDomain($domain->getId(), $context);
        $salesChannelContext = null;
        $payload = [];

        foreach ([LlmsDocumentDefinition::VARIANT_SHORT, LlmsDocumentDefinition::VARIANT_FULL] as $variant) {
            $document = $existing[$variant] ?? null;

            // Redaktionell bearbeitete Dokumente bleiben stehen. Nur `force` setzt sie zurück.
            if (!$force && $document !== null && $document->isCustom()) {
                continue;
            }

            // Der Kontext entsteht erst bei Bedarf und höchstens einmal je Domain. Sind beide
            // Fassungen bearbeitet, kostet die Domain damit keinen Kontextaufbau.
            $salesChannelContext ??= $this->createSalesChannelContext($domain);

            // Die vorhandene Kennung macht den Upsert zum Update; eine neue liefe in den eindeutigen
            // Schlüssel auf Domain und Variante.
            $payload[] = [
                'id' => $document?->getId() ?? Uuid::randomHex(),
                'salesChannelDomainId' => $domain->getId(),
                'variant' => $variant,
                'content' => $this->renderContent($domain, $variant, $salesChannelContext),
                'isCustom' => false,
                'generatedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ];
        }

        if ($payload === []) {
            return 0;
        }

        $this->documentRepository->upsert($payload, $context);
        $this->invalidate($domain->getId(), array_column($payload, 'variant'));

        return \count($payload);
    }

    /**
     * Speichert einen in der Verwaltung bearbeiteten Inhalt. Das Dokument gilt danach als
     * redaktionell gepflegt, und Generierungen ohne `force` lassen es stehen. Eine unbekannte
     * Kennung endet in `LlmsDocumentException::documentNotFound()`.
     */
    public function saveCustomContent(string $documentId, string $content, Context $context): void
    {
        $document = $this->documentRepository->search(new Criteria([$documentId]), $context)->getEntities()->first();
        if (!$document instanceof LlmsDocumentEntity) {
            throw LlmsDocumentException::documentNotFound($documentId);
        }

        // Zeilenenden aus dem Browser vereinheitlichen und genau einen Zeilenumbruch ans Ende setzen,
        // so wie ihn auch der generierte Text trägt.
        $this->documentRepository->update([[
            'id' => $documentId,
            'content' => rtrim(str_replace(["\r\n", "\r"], "\n", $content)) . "\n",
            'isCustom' => true,
            'generatedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]], $context);

        $this->invalidate($document->getSalesChannelDomainId(), [$document->getVariant()]);
    }

    /**
     * Verwirft die Bearbeitung und stellt den automatischen Stand wieder her. Über
     * `generateForDomain()` mit `force` trifft das beide Fassungen der Domain, nicht nur das
     * angegebene Dokument. Fehlen Dokument oder Domain, endet der Aufruf in einer
     * `LlmsDocumentException` mit Statuscode 404.
     */
    public function regenerate(string $documentId, Context $context): void
    {
        $document = $this->documentRepository->search(new Criteria([$documentId]), $context)->getEntities()->first();
        if (!$document instanceof LlmsDocumentEntity) {
            throw LlmsDocumentException::documentNotFound($documentId);
        }

        $domain = $this->domainRepository
            ->search(new Criteria([$document->getSalesChannelDomainId()]), $context)
            ->getEntities()
            ->first();

        if (!$domain instanceof SalesChannelDomainEntity) {
            throw LlmsDocumentException::domainNotFound($document->getSalesChannelDomainId());
        }

        $this->generateForDomain($domain, $context, true);
    }

    /**
     * @return list<SalesChannelDomainEntity>
     */
    private function loadActiveDomains(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannel.active', true));
        $criteria->addFilter(new EqualsFilter('salesChannel.typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));

        return array_values($this->domainRepository->search($criteria, $context)->getEntities()->getElements());
    }

    /**
     * @return array<string, LlmsDocumentEntity> Variante => Dokument
     */
    private function loadDocumentsOfDomain(string $domainId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelDomainId', $domainId));

        $documents = [];
        foreach ($this->documentRepository->search($criteria, $context)->getEntities() as $document) {
            $documents[$document->getVariant()] = $document;
        }

        return $documents;
    }

    /**
     * Baut einen Gastkontext in Sprache und Währung der Domain, ohne Request und ohne Session, weil
     * die Generierung auch in der geplanten Aufgabe läuft. Der Zufallstoken gehört zu keinem Besucher.
     */
    private function createSalesChannelContext(SalesChannelDomainEntity $domain): SalesChannelContext
    {
        return $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $domain->getSalesChannelId(),
            [
                SalesChannelContextService::LANGUAGE_ID => $domain->getLanguageId(),
                SalesChannelContextService::CURRENCY_ID => $domain->getCurrencyId(),
                SalesChannelContextService::DOMAIN_ID => $domain->getId(),
            ]
        );
    }

    /**
     * Rendert über die Plugin-Vorlage und ersetzt danach die SEO-Platzhalter durch absolute Adressen
     * der Domain. Die Reihenfolge trägt: Ein Theme, das den Block erweitert, darf selbst Platzhalter
     * einsetzen, und auch die werden noch ersetzt.
     */
    private function renderContent(
        SalesChannelDomainEntity $domain,
        string $variant,
        SalesChannelContext $salesChannelContext,
    ): string {
        $url = rtrim($domain->getUrl(), '/');
        $config = $this->configProvider->load($domain->getSalesChannelId());

        $content = $this->generator->generate(
            $salesChannelContext,
            $url,
            $variant === LlmsDocumentDefinition::VARIANT_FULL,
            $config
        );

        $rendered = $this->twig->render($this->templateFinder->find(self::TEMPLATE), ['llmsContent' => $content]);

        return $this->seoUrlReplacer->replace($rendered, $url, $salesChannelContext);
    }

    /**
     * @param list<string> $variants
     */
    private function invalidate(string $domainId, array $variants): void
    {
        $this->cacheInvalidator->invalidate(array_map(
            static fn (string $variant): string => LlmsDocumentDefinition::cacheTag($domainId, $variant),
            $variants
        ));
    }
}
