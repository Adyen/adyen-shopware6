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
        $schemaManager = $connection->getSchemaManager();

        // check if table exists
        if (!$schemaManager->tablesExist(['adyen_payment'])) {
            return;
        }

        // check if index already exists
        $indexes = $schemaManager->listTableIndexes('adyen_payment');
        foreach ($indexes as $index) {
            if ($index->getName() === 'UQ_ADYEN_PAYMENT_PSPREFERENCE') {
                return;
            }
        }

        $query = <<<SQL
            ALTER TABLE `adyen_payment` ADD CONSTRAINT `UQ_ADYEN_PAYMENT_PSPREFERENCE` UNIQUE (`pspreference`)
        SQL;

        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
