<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Core\Content\LlmsDocument;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainDefinition;

/**
 * Definition für `rc_ai_discovery_llms_document` — der gespeicherte Inhalt einer llms-Datei.
 *
 * Geschlüsselt auf die Sales-Channel-Domain, weil sie Sprache und Basis-URL festlegt: der Inhalt
 * enthält bereits absolute Links und ist damit domain-spezifisch. Pro Domain gibt es je eine
 * Kurz- und eine Langfassung (UNIQUE auf domain + variant).
 */
final class LlmsDocumentDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'rc_ai_discovery_llms_document';

    public const VARIANT_SHORT = 'short';

    public const VARIANT_FULL = 'full';

    /**
     * Cache-Tag eines Dokuments: die Route setzt es beim Ausliefern, die Generierung räumt damit
     * gezielt den HTTP-Cache dieser einen Datei ab.
     */
    public static function cacheTag(string $salesChannelDomainId, string $variant): string
    {
        return 'rc-ai-discovery-llms-' . $salesChannelDomainId . '-' . $variant;
    }

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return LlmsDocumentEntity::class;
    }

    public function getCollectionClass(): string
    {
        return LlmsDocumentCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new FkField('sales_channel_domain_id', 'salesChannelDomainId', SalesChannelDomainDefinition::class))
                ->addFlags(new ApiAware(), new Required()),
            (new StringField('variant', 'variant', 16))->addFlags(new ApiAware(), new Required()),
            (new LongTextField('content', 'content'))->addFlags(new ApiAware(), new Required()),
            (new BoolField('is_custom', 'isCustom'))->addFlags(new ApiAware(), new Required()),
            (new DateTimeField('generated_at', 'generatedAt'))->addFlags(new ApiAware(), new Required()),
            new ManyToOneAssociationField('salesChannelDomain', 'sales_channel_domain_id', SalesChannelDomainDefinition::class, 'id', false),
        ]);
    }
}
