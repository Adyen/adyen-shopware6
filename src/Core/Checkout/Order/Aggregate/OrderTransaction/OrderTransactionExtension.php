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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction;

use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntityDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ReadProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class OrderTransactionExtension extends EntityExtension
{
    /**
     * @inheritDoc
     */
    public function getDefinitionClass(): string
    {
        return OrderTransactionDefinition::class;
    }

    public function extendFields(FieldCollection $collection): void
    {
        $field = new OneToManyAssociationField(
            'adyenPaymentResponses',
            PaymentResponseEntityDefinition::class,
            'order_transaction_id'
        );

        // Ensure the data is not available via the Store API in older Shopware versions.
        if (!class_exists(ApiAware::class) && class_exists(ReadProtected::class)) {
            $field->addFlags(new ReadProtected(SalesChannelApiSource::class));
        }

        $collection->add($field);
    }
}
