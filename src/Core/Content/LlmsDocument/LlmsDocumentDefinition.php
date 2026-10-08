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
 * Der gespeicherte, auslieferungsfertige Inhalt einer llms-Datei.
 *
 * Geschlüsselt ist auf die Domain und nicht auf den Verkaufskanal, weil die Domain Sprache und
 * Basisadresse festlegt und der Text bereits absolute Links enthält. Je Domain gibt es eine Kurz-
 * und eine Langfassung; der eindeutige Schlüssel auf Domain und Variante steht in der Migration.
 */
final class LlmsDocumentDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'rc_ai_discovery_llms_document';

    /**
     * Kurzfassung für `/llms.txt`. Die Langfassung für `/llms-full.txt` ergänzt Beschreibungen an
     * den Links und die Seiten der Footer-Kategorie.
     */
    public const VARIANT_SHORT = 'short';

    public const VARIANT_FULL = 'full';

    /**
     * Cache-Tag eines Dokuments. Die Storefront-Route setzt es beim Ausliefern, Generierung und
     * Bearbeitung räumen damit den HTTP-Cache genau dieser einen Datei ab.
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

    /**
     * `variant` ist auf 16 Zeichen begrenzt wie die Spalte in der Migration; die beiden Werte sind
     * deutlich kürzer. `createdAt` und `updatedAt` ergänzt der Kern selbst.
     */
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
