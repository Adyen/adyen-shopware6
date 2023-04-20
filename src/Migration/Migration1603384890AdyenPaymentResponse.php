<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1603384890AdyenPaymentResponse extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1603384890;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            alter table `adyen_payment_response`
            add column order_transaction_id binary(16) after sales_channel_api_context_token;
        ");
        $connection->executeStatement("
            update `adyen_payment_response` apr
                inner join `order` o on apr.order_number = o.order_number
                inner join `order_transaction` ot on o.id = ot.order_id and o.version_id = ot.order_version_id
            set apr.order_transaction_id = ot.id
            where 1=1;
        ");
        $connection->executeStatement("
            alter table `adyen_payment_response`
                drop column order_number,
                drop column sales_channel_api_context_token;
        ");
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement("
            alter table `adyen_payment_response`
                add column order_number varchar(64) after id,
                add column sales_channel_api_context_token varchar(255) after order_number;        ");
        $connection->executeStatement("
            update `adyen_payment_response` apr
                inner join `order` o on apr.order_number = o.order_number
                inner join `order_transaction` ot on o.id = ot.order_id and o.version_id = ot.order_version_id
            set apr.order_number = o.order_number
            where 1=1;
        ");
        $result = $connection->executeQuery("
            show columns from adyen_payment_response like 'order_transaction_id';
        ");
        if ($result->rowCount()) {
            $connection->executeStatement("
                alter table `adyen_payment_response`
                drop column order_transaction_id;
            ");
        }
    }
}
