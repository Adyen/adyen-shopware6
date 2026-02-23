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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Core\Checkout\Payment\PaymentMethod;

use Shopware\Core\Checkout\Payment\PaymentMethodDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Computed;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Runtime;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ObjectField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class PaymentMethodExtension extends EntityExtension
{
    /**
     * @param FieldCollection $collection
     *
     * @return void
     */
    public function extendFields(FieldCollection $collection): void
    {
        $field = new ObjectField(
            'adyen_data',
            'adyenData'
        );

        $field->addFlags(new Runtime(), new Computed());
        if (class_exists(ApiAware::class)) {
            $field->addFlags(new ApiAware());
        }

        $collection->add($field);
    }

    /**
     * @return string
     */
    public function getDefinitionClass(): string
    {
        return PaymentMethodDefinition::class;
    }

    /**
     * @return string
     */
    public function getEntityName(): string
    {
        return 'payment_method';
    }
}
