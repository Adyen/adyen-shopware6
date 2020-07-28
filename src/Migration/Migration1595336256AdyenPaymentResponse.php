<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1595336256AdyenPaymentResponse extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1595336256;
    }

    public function update(Connection $connection): void
    {
        // TODO add FKs
        $connection->executeUpdate(<<<SQL
            CREATE TABLE IF NOT EXISTS `adyen_payment_response` (
                `id` BINARY(16) NOT NULL,
                `order_number` VARCHAR(64) NOT NULL,
                `sales_channel_api_context_token` VARCHAR(255) NOT NULL,
                `result_code` VARCHAR(255) NOT NULL,
                `response` TEXT,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
