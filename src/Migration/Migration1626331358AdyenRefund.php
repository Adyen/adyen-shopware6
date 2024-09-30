<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1626331358AdyenRefund extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1626331358;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `adyen_refund` (
                `id` BINARY(16) NOT NULL,
                `order_transaction_id` BINARY(16)  not NULL,
                `psp_reference` VARCHAR(255),
                `source` VARCHAR(255) NOT NULL,
                `status` VARCHAR(255) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                `amount` INT(11) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
