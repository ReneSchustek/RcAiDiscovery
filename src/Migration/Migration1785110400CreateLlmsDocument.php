<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Legt die Tabelle der gespeicherten llms-Dateien je Domain an.
 *
 * Der eindeutige Schlüssel auf Domain und Variante hält je Domain genau eine Kurz- und eine
 * Langfassung. Die Generierung übernimmt beim Upsert die Kennung des vorhandenen Dokuments, sonst
 * scheiterte der zweite Lauf an diesem Schlüssel. Wird eine Domain gelöscht, verschwinden ihre
 * Dokumente über `ON DELETE CASCADE` mit.
 */
final class Migration1785110400CreateLlmsDocument extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785110400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `rc_ai_discovery_llms_document` (
                `id`                        BINARY(16)   NOT NULL,
                `sales_channel_domain_id`   BINARY(16)   NOT NULL,
                `variant`                   VARCHAR(16)  NOT NULL,
                `content`                   LONGTEXT     NOT NULL,
                `is_custom`                 TINYINT(1)   NOT NULL DEFAULT 0,
                `generated_at`              DATETIME(3)  NOT NULL,
                `created_at`                DATETIME(3)  NOT NULL,
                `updated_at`                DATETIME(3)  NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.rc_ai_discovery_llms_document.domain_variant`
                    (`sales_channel_domain_id`, `variant`),
                CONSTRAINT `fk.rc_ai_discovery_llms_document.sales_channel_domain_id`
                    FOREIGN KEY (`sales_channel_domain_id`)
                    REFERENCES `sales_channel_domain` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Die Migration legt nur an; es gibt nichts, das erst nach einem Update entfernt werden müsste.
    }
}
