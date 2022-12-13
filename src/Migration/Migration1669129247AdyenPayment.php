<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1669129247AdyenPayment extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1669129247;
    }

    public function update(Connection $connection): void
    {
        $query = <<<SQL
            CREATE TABLE IF NOT EXISTS `adyen_payment` (
                `id` BINARY(16) NOT NULL,
                `pspreference` VARCHAR(255) DEFAULT NULL COMMENT 'PSP Reference',
                `original_reference` VARCHAR(255) DEFAULT NULL COMMENT 'Original reference',
                `merchant_reference` VARCHAR(255) DEFAULT NULL COMMENT 'Merchant reference',
                `merchant_order_reference` VARCHAR(255) DEFAULT NULL COMMENT 'Merchant order reference',
                `order_transaction_id` BINARY(16)  not NULL COMMENT 'Order transaction Id',
                `payment_method` VARCHAR(255) NOT NULL COMMENT 'Payment method',
                `amount_value` INT(11) NOT NULL COMMENT 'Amount value',
                `amount_currency` VARCHAR(255) NOT NULL COMMENT 'Amount currency',
                `additional_data` text COMMENT 'Additional data',
                `capture_mode` VARCHAR(255) DEFAULT NULL COMMENT 'Capture mode',
                `created_at` DATETIME(3) NOT NULL COMMENT 'Created at',
                `updated_at` DATETIME(3) DEFAULT NULL COMMENT 'Updated at',
                PRIMARY KEY (`id`),
                KEY `ADYEN_PAYMENT_MERCHANT_REFERENCE` (`merchant_reference`),
                KEY `ADYEN_PAYMENT_MERCHANT_ORDER_REFERENCE` (`merchant_order_reference`),
                CONSTRAINT `fk.adyen_payment.order_transaction_id`
                    FOREIGN KEY (order_transaction_id) references `order_transaction` (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $connection->executeUpdate($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
