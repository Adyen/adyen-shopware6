<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1681716164AlterAdyenPayment extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1681716164;
    }

    public function update(Connection $connection): void
    {
        $query = <<<SQL
            ALTER TABLE `adyen_payment` ADD CONSTRAINT `UQ_ADYEN_PAYMENT_PSPREFERENCE` UNIQUE (`pspreference`)
        SQL;

        $connection->executeUpdate($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
