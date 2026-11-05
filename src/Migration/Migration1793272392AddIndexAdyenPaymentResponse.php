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
        // Skip if any index already starts with order_transaction_id (e.g. one added manually by the merchant)
        $indexExists = (int)$connection->fetchOne(<<<SQL
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'adyen_payment_response'
              AND COLUMN_NAME = 'order_transaction_id'
              AND SEQ_IN_INDEX = 1;
SQL
        );

        if ($indexExists > 0) {
            return;
        }

        $connection->executeStatement(<<<SQL
            CREATE INDEX `idx.adyen_payment_response.order_transaction_id`
                ON `adyen_payment_response` (`order_transaction_id`, `created_at`);
SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
