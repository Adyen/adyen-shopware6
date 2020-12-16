<?php declare(strict_types=1);
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Entity\Notification;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class NotificationEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'adyen_notification';

    /**
     * @return string
     */
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return NotificationEntityCollection::class;
    }

    public function getEntityClass(): string
    {
        return NotificationEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            new StringField('pspreference', 'pspreference'),
            new StringField('original_reference', 'originalReference'),
            new StringField('merchant_reference', 'merchantReference'),
            new StringField('event_code', 'eventCode'),
            new BoolField('success', 'success'),
            new StringField('payment_method', 'paymentMethod'),
            new StringField('amount_value', 'amountValue'),
            new StringField('amount_currency', 'amountCurrency'),
            new StringField('reason', 'reason'),
            new BoolField('live', 'live'),
            new StringField('additional_data', 'additionalData'),
            new BoolField('done', 'done'),
            new BoolField('processing', 'processing'),
            new DateTimeField('scheduled_processing_time', 'scheduledProcessingTime'),
            new IntField('error_count', 'errorCount'),
            new StringField('error_message', 'errorMessage'),
            new CreatedAtField(),
            new UpdatedAtField()
        ]);
    }
}
