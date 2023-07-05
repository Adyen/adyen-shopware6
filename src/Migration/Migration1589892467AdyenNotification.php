<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1589892467AdyenNotification extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1589892467;
    }

    public function update(Connection $connection): void
    {
        $query = <<<SQL
            CREATE TABLE IF NOT EXISTS `adyen_notification` (
                `id` BINARY(16) NOT NULL,
                `pspreference` VARCHAR(255) DEFAULT NULL COMMENT 'PSP Reference',
                `original_reference` VARCHAR(255) DEFAULT NULL COMMENT 'Original reference',
                `merchant_reference` VARCHAR(255) DEFAULT NULL COMMENT 'Merchant reference',
                `event_code` VARCHAR(255) DEFAULT NULL COMMENT 'Event code',
                `success` TINYINT(1) DEFAULT NULL COMMENT 'Success',
                `payment_method` VARCHAR(255) DEFAULT NULL COMMENT 'Payment method',
                `amount_value` VARCHAR(255) DEFAULT NULL COMMENT 'Amount value',
                `amount_currency` VARCHAR(255) DEFAULT NULL COMMENT 'Amount currency',
                `reason` VARCHAR(255) DEFAULT NULL COMMENT 'reason',
                `live` TINYINT(1) DEFAULT NULL COMMENT 'Sent from Adyen live platform',
                `additional_data` text COMMENT 'Additional data',
                `done` TINYINT(1) NOT NULL DEFAULT '0' COMMENT 'Done',
                `processing` TINYINT(1) DEFAULT '0' COMMENT 'Adyen notification cron processing',
                `error_count` INT(11) DEFAULT NULL COMMENT 'Error count',
                `error_message` text DEFAULT NULL COMMENT 'Error messages',
                `created_at` DATETIME(3) NOT NULL COMMENT 'Created at',
                `updated_at` DATETIME(3) NULL COMMENT 'Updated at',
                PRIMARY KEY (`id`),
                KEY `ADYEN_NOTIFICATION_PSPREFERENCE` (`pspreference`),
                KEY `ADYEN_NOTIFICATION_EVENT_CODE` (`event_code`),
                KEY `ADYEN_NOTIFICATION_PSPREFERENCE_EVENT_CODE` (`pspreference`,`event_code`),
                KEY `ADYEN_NOTIFICATION_MERCHANT_REFERENCE_EVENT_CODE` (`merchant_reference`,`event_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
