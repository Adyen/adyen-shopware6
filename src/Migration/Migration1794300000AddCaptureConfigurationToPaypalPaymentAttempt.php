<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Stores the capture configuration that was sent with the PayPal /payments request, so the same configuration is
 * stored on the order transaction when the order is created later.
 */
class Migration1794300000AddCaptureConfigurationToPaypalPaymentAttempt extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794300000;
    }

    public function update(Connection $connection): void
    {
        $columnExists = (int)$connection->fetchOne(<<<SQL
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'adyen_paypal_payment_attempt'
              AND COLUMN_NAME = 'capture_configuration';
SQL
        );

        if ($columnExists > 0) {
            return;
        }

        $connection->executeStatement(<<<SQL
            ALTER TABLE `adyen_paypal_payment_attempt`
                ADD COLUMN `capture_configuration` JSON NULL AFTER `reversal_psp_reference`;
SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
