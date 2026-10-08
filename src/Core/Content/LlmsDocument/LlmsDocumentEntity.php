<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;

/**
 * Eine gespeicherte llms-Datei: fertiger Text mit absoluten Links, den die Storefront-Route ohne
 * weitere Abfrage ausliefert.
 */
class LlmsDocumentEntity extends Entity
{
    use EntityIdTrait;

    protected string $salesChannelDomainId;

    protected string $variant;

    protected string $content;

    /**
     * true, sobald der Inhalt in der Verwaltung gespeichert wurde. Weder die geplante Aufgabe noch
     * „Jetzt aktualisieren" überschreiben ihn dann; nur „Neu generieren" setzt ihn zurück.
     */
    protected bool $isCustom;

    /**
     * Zeitpunkt des letzten Schreibens, auch einer Bearbeitung in der Verwaltung.
     */
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
