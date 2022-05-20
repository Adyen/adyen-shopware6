<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1653031120AdyenPaymentResponse extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1653031120;
    }

    public function update(Connection $connection): void
    {
        $connection->executeUpdate("
            alter table `adyen_payment_response`
            add column psp_reference varchar(64) NULL after order_transaction_id;
        ");
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
