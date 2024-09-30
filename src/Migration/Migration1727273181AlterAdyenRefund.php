<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1727273181AlterAdyenRefund extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1727273181;
    }

    public function update(Connection $connection): void
    {
        try {
            $connection->executeStatement(<<<SQL
            ALTER TABLE `adyen_refund` DROP FOREIGN KEY `fk.adyen_refund.order_transaction_id`;
        SQL
            );
        } catch (Exception) {
            // Intentionally left empty, if foreign key is missing, the migration should be skipped.
        }
    }
}
