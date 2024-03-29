<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1607529255AdyenNotification extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1607529255;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            ALTER TABLE `adyen_notification`
            ADD COLUMN `scheduled_processing_time` DATETIME(3) NULL AFTER `processing`;
        ");
        $connection->executeStatement("
            ALTER TABLE `adyen_notification`
            CHANGE `scheduled_processing_time` `scheduled_processing_time` DATETIME(3) NULL COMMENT 'Scheduled For';
        ");
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
