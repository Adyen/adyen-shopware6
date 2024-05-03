<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1713255011AlterAdyenPayment extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1713255011;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
            ALTER TABLE `adyen_payment` ADD COLUMN `total_refunded` int DEFAULT(0) NOT NULL;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
