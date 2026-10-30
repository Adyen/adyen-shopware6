<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1793272392AddIndexAdyenPaymentResponse extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793272392;
    }

    public function update(Connection $connection): void
    {
        if (!$this->paymentResponseIndexExists($connection, 'idx.adyen_payment_response.order_transaction_id')) {
            $connection->executeStatement(<<<SQL
                CREATE INDEX `idx.adyen_payment_response.order_transaction_id`
                    ON `adyen_payment_response` (`order_transaction_id`, `created_at`)
                    ALGORITHM=INPLACE LOCK=NONE;
SQL
            );
        }

        // Redundant, pspreference is already indexed by UQ_ADYEN_PAYMENT_RESPONSE_PSPREFERENCE.
        // Dropped here and not in updateDestructive(), because plugin destructive migrations do not run on plugin
        // update, and running them manually would also execute Migration1603384890::updateDestructive().
        if ($this->paymentResponseIndexExists($connection, 'ADYEN_PAYMENT_RESPONSE_PSPREFERENCE')
            && $this->paymentResponseIndexExists($connection, 'UQ_ADYEN_PAYMENT_RESPONSE_PSPREFERENCE')) {
            $connection->executeStatement(<<<SQL
                DROP INDEX `ADYEN_PAYMENT_RESPONSE_PSPREFERENCE` ON `adyen_payment_response`;
SQL
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function paymentResponseIndexExists(Connection $connection, string $indexName): bool
    {
        return (int)$connection->fetchOne(
            <<<SQL
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'adyen_payment_response'
              AND INDEX_NAME = :indexName;
SQL,
            ['indexName' => $indexName]
        ) > 0;
    }
}
