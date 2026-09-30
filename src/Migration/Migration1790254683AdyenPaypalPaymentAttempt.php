<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1790254683AdyenPaypalPaymentAttempt extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790254683;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `adyen_paypal_payment_attempt` (
                `id` BINARY(16) NOT NULL,
                `merchant_reference` VARCHAR(255) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `psp_reference` VARCHAR(255) NULL,
                `status` VARCHAR(32) NOT NULL,
                `reversal_psp_reference` VARCHAR(255) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.adyen_paypal_payment_attempt.merchant_reference` (`merchant_reference`),
                KEY `idx.adyen_paypal_payment_attempt.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
