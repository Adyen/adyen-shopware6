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

namespace Adyen\Shopware\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when the Shopware order could not be created and the PayPal payment has been reversed.
 * The original failure is the previous exception.
 */
class PaymentReversedException extends RuntimeException
{
    /**
     * @param Throwable $orderCreationFailure
     */
    public function __construct(Throwable $orderCreationFailure)
    {
        parent::__construct($orderCreationFailure->getMessage(), 0, $orderCreationFailure);
    }
}
