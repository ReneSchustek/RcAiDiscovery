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
 * Der gespeicherte Text ist auslieferungsfertig: die SEO-Platzhalter sind bereits durch absolute
 * URLs ersetzt. Deshalb braucht die Storefront-Route beim Abruf keinen Sales-Channel-Kontext mehr
 * — und ohne den entsteht dort auch keine Session.
 *
 * Bewusst nicht `final`: der Dienst wird von der Storefront-Route wie vom Admin genutzt und muss
 * für deren Tests ersetzbar bleiben (und ist so auch dekorierbar).
 */
class LlmsDocumentGenerator
{
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
     * Erzeugt die Dateien aller aktiven Storefront-Domains.
     *
     * @param bool $force true = auch im Admin bearbeitete Dokumente überschreiben
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
     * @return int Anzahl geschriebener Dokumente dieser Domain
     */
    public function generateForDomain(SalesChannelDomainEntity $domain, Context $context, bool $force = false): int
    {
        $existing = $this->loadDocumentsOfDomain($domain->getId(), $context);
        $salesChannelContext = null;
        $payload = [];

        foreach ([LlmsDocumentDefinition::VARIANT_SHORT, LlmsDocumentDefinition::VARIANT_FULL] as $variant) {
            $document = $existing[$variant] ?? null;

            // Redaktionell bearbeitete Dokumente bleiben stehen — nur ein bewusstes
            // „neu generieren" (force) setzt sie zurück.
            if (!$force && $document !== null && $document->isCustom()) {
                continue;
            }

            $salesChannelContext ??= $this->createSalesChannelContext($domain);

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
     * Speichert einen im Admin bearbeiteten Inhalt; das Dokument gilt danach als redaktionell
     * gepflegt und wird von der geplanten Generierung nicht mehr angefasst.
     */
    public function saveCustomContent(string $documentId, string $content, Context $context): void
    {
        $document = $this->documentRepository->search(new Criteria([$documentId]), $context)->getEntities()->first();
        if (!$document instanceof LlmsDocumentEntity) {
            throw LlmsDocumentException::documentNotFound($documentId);
        }

        $this->documentRepository->update([[
            'id' => $documentId,
            'content' => rtrim(str_replace(["\r\n", "\r"], "\n", $content)) . "\n",
            'isCustom' => true,
            'generatedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]], $context);

        $this->invalidate($document->getSalesChannelDomainId(), [$document->getVariant()]);
    }

    /**
     * Verwirft die Bearbeitung eines Dokuments und stellt den automatischen Stand wieder her.
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
     * Baut den Kontext ohne Request und ohne Session — die Generierung läuft im Hintergrund.
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
     * Rendert über das Plugin-Template (Erweiterungspunkt für Themes) und ersetzt anschließend die
     * SEO-Platzhalter durch absolute URLs der Domain.
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
