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
        $connection->executeUpdate("
            alter table `adyen_notification`
            add column scheduled_processing_time datetime null after processing;
        ");
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
