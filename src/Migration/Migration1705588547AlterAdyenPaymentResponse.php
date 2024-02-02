<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1705588547AlterAdyenPaymentResponse extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1705588547;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
            ALTER TABLE `adyen_payment_response` ADD COLUMN `pspreference` varchar(255);
        SQL);

        $connection->executeStatement(<<<SQL
            ALTER TABLE `adyen_payment_response`
                ADD CONSTRAINT `UQ_ADYEN_PAYMENT_RESPONSE_PSPREFERENCE` 
                    UNIQUE (`pspreference`);
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE INDEX `ADYEN_PAYMENT_RESPONSE_PSPREFERENCE`
                ON `adyen_payment_response` (`pspreference`);
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
