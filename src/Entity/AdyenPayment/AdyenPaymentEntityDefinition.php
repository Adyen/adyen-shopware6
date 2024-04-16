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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Entity\AdyenPayment;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class AdyenPaymentEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'adyen_payment';

    /**
     * @return string
     */
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return AdyenPaymentEntityCollection::class;
    }

    public function getEntityClass(): string
    {
        return AdyenPaymentEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField(
                'order_transaction_id',
                'orderTransactionId',
                OrderTransactionDefinition::class
            ))->addFlags(new Required()),
            new StringField('pspreference', 'pspreference'),
            new StringField('original_reference', 'originalReference'),
            new StringField('merchant_reference', 'merchantReference'),
            new StringField('merchant_order_reference', 'merchantOrderReference'),
            new StringField('payment_method', 'paymentMethod'),
            new IntField('amount_value', 'amountValue'),
            new IntField('total_refunded', 'totalRefunded'),
            new StringField('amount_currency', 'amountCurrency'),
            new LongTextField('additional_data', 'additionalData'),
            new StringField('capture_mode', 'captureMode'),
            new CreatedAtField(),
            new UpdatedAtField(),
            new ManyToOneAssociationField(
                'orderTransaction',
                'order_transaction_id',
                OrderTransactionDefinition::class,
                'id',
                true
            )
        ]);
    }
}
