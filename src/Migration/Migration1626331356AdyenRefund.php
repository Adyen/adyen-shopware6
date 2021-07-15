<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1626331356AdyenRefund extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1626331356;
    }

    public function update(Connection $connection): void
    {
        $connection->executeUpdate(<<<SQL
            CREATE TABLE IF NOT EXISTS `adyen_refund` (
                `id` BINARY(16) NOT NULL,
                `order_id` BINARY(16)  not NULL,
                `psp_reference` VARCHAR(255),
                `source` VARCHAR(255) NOT NULL,
                `status` VARCHAR(255) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `amount` INT NOT NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk.adyen_refund.order_id`
                    FOREIGN KEY (order_id) references `order` (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
