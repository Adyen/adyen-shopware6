<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1627977666AdyenCreditcardConfig extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1627977666;
    }

    public function update(Connection $connection): void
    {
        $connection->insert('system_config', [
            'configuration_key' => 'AdyenPaymentShopware6.config.enableSaveCreditCard',
            'configuration_value' => '{"_value": true}',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
