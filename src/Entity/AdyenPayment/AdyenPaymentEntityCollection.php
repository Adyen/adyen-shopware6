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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Entity\AdyenPayment;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(AdyenPaymentEntity $entity)
 * @method void              set(string $key, AdyenPaymentEntity $entity)
 * @method AdyenPaymentEntity[]    getIterator()
 * @method AdyenPaymentEntity[]    getElements()
 * @method AdyenPaymentEntity|null get(string $key)
 * @method AdyenPaymentEntity|null first()
 * @method AdyenPaymentEntity|null last()
 */
class AdyenPaymentEntityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AdyenPaymentEntity::class;
    }
}
