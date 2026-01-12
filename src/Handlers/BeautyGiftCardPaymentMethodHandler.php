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

namespace Adyen\Shopware\Handlers;

/**
 * @deprecated Individual gift card payment methods were deprecated and will be removed on v4.0.0.
 * Use GiftCardPaymentMethod instead.
 */
class BeautyGiftCardPaymentMethodHandler extends AbstractPaymentMethodHandler
{
    public static bool $isGiftCard = true;

    /**
     * @return string
     */
    public static function getPaymentMethodCode(): string
    {
        return 'giftcard';
    }

    public static function getBrand(): string
    {
        return 'beautycadeaukaart';
    }
}
