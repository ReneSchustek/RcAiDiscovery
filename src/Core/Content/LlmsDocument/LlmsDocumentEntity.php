<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;

/**
 * Eine gespeicherte llms-Datei: fertiger Text inklusive absoluter Links, bereit zum Ausliefern.
 */
class LlmsDocumentEntity extends Entity
{
    use EntityIdTrait;

    protected string $salesChannelDomainId;

    protected string $variant;

    protected string $content;

    /**
     * true = im Admin bearbeitet; die geplante Generierung lässt das Dokument dann in Ruhe.
     */
    protected bool $isCustom;

    protected \DateTimeInterface $generatedAt;

    protected ?SalesChannelDomainEntity $salesChannelDomain = null;

    public function getSalesChannelDomainId(): string
    {
        return $this->salesChannelDomainId;
    }

    public function setSalesChannelDomainId(string $salesChannelDomainId): void
    {
        $this->salesChannelDomainId = $salesChannelDomainId;
    }

    public function getVariant(): string
    {
        return $this->variant;
    }

    public function setVariant(string $variant): void
    {
        $this->variant = $variant;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function isCustom(): bool
    {
        return $this->isCustom;
    }

    public function setIsCustom(bool $isCustom): void
    {
        $this->isCustom = $isCustom;
    }

    public function getGeneratedAt(): \DateTimeInterface
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(\DateTimeInterface $generatedAt): void
    {
        $this->generatedAt = $generatedAt;
    }

    public function getSalesChannelDomain(): ?SalesChannelDomainEntity
    {
        return $this->salesChannelDomain;
    }

    public function setSalesChannelDomain(?SalesChannelDomainEntity $salesChannelDomain): void
    {
        $this->salesChannelDomain = $salesChannelDomain;
    }
}
