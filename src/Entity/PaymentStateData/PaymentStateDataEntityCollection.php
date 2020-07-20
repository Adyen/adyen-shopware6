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

namespace Adyen\Shopware\Entity\PaymentStateData;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                    add(PaymentStateDataEntity $entity)
 * @method void                    set(string $key, PaymentStateDataEntity $entity)
 * @method PaymentStateDataEntity[]    getIterator()
 * @method PaymentStateDataEntity[]    getElements()
 * @method PaymentStateDataEntity|null get(string $key)
 * @method PaymentStateDataEntity|null first()
 * @method PaymentStateDataEntity|null last()
 */
class PaymentStateDataEntityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return PaymentStateDataEntity::class;
    }
}
