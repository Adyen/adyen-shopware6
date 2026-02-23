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

namespace Adyen\Shopware\Entity\PaymentCapture;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(PaymentCaptureEntity $entity)
 * @method void              set(string $key, PaymentCaptureEntity $entity)
 * @method PaymentCaptureEntity[]    getIterator()
 * @method PaymentCaptureEntity[]    getElements()
 * @method PaymentCaptureEntity|null get(string $key)
 * @method PaymentCaptureEntity|null first()
 * @method PaymentCaptureEntity|null last()
 */
class PaymentCaptureEntityCollection extends EntityCollection
{
    /**
     * @return string
     */
    protected function getExpectedClass(): string
    {
        return PaymentCaptureEntity::class;
    }
}
