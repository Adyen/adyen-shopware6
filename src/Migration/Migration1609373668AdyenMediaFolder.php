<?php declare(strict_types=1);

namespace Adyen\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1609373668AdyenMediaFolder extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1609373668;
    }

    public function update(Connection $connection): void
    {
        if ($this->adyenMediaFolderExists($connection)) {
            return;
        }
        $connection->transactional(function (Connection $connection) {
            $defaultFolderId = Uuid::randomBytes();
            $configurationId = Uuid::randomBytes();
            $connection->executeUpdate('
                INSERT INTO `media_default_folder` (`id`, `association_fields`, `entity`, `created_at`)
                VALUES (:id, :associationFields, :entity, :createdAt)
            ', [
                'id' => $defaultFolderId,
                'associationFields' => '[]',
                'entity' => 'adyen',
                'createdAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT)
            ]);
            $connection->executeUpdate("
                INSERT INTO `media_folder_configuration`
                    (`id`, `thumbnail_quality`, `create_thumbnails`, `private`, created_at)
                VALUES
                    (:id, 80, 1, 0, :createdAt)
            ", [
                'id' => $configurationId,
                'createdAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT)
            ]);
            $connection->executeUpdate('
                INSERT INTO `media_folder`
                    (
                        `id`,
                        `name`,
                        `default_folder_id`,
                        `media_folder_configuration_id`,
                        `use_parent_configuration`,
                        `child_count`,
                        `created_at`
                    )
                VALUES
                    (:id, :folderName, :defaultFolderId, :configurationId, 0, 0, :createdAt)
            ', [
                'id' => Uuid::randomBytes(),
                'folderName' => 'Adyen Media',
                'defaultFolderId' => $defaultFolderId,
                'configurationId' => $configurationId,
                'createdAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        });
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function adyenMediaFolderExists(Connection $connection): bool
    {
        $query = $connection->executeQuery("SELECT `id` FROM `media_default_folder` WHERE `entity`='adyen'");
        return $query->rowCount() > 0;
    }
}
