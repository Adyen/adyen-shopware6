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

namespace Adyen\Shopware\Entity\PaypalPaymentAttempt;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                            add(PaypalPaymentAttemptEntity $entity)
 * @method void                            set(string $key, PaypalPaymentAttemptEntity $entity)
 * @method PaypalPaymentAttemptEntity[]    getIterator()
 * @method PaypalPaymentAttemptEntity[]    getElements()
 * @method PaypalPaymentAttemptEntity|null get(string $key)
 * @method PaypalPaymentAttemptEntity|null first()
 * @method PaypalPaymentAttemptEntity|null last()
 */
class PaypalPaymentAttemptEntityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return PaypalPaymentAttemptEntity::class;
    }
}
