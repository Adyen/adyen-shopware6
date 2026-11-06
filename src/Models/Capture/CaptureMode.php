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
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Models\Capture;

/**
 * Capture mode of a payment, configured globally and optionally per payment method.
 */
enum CaptureMode: string
{
    /**
     * Payment-method level only: the payment method follows the global capture mode.
     */
    case GLOBAL = 'global';

    /**
     * Adyen captures the payment immediately.
     */
    case IMMEDIATE = 'immediate';

    /**
     * Adyen captures the payment automatically after the configured number of days.
     */
    case DELAYED = 'delayed';

    /**
     * The payment is only authorised and is captured later by the merchant.
     */
    case MANUAL = 'manual';

    /**
     * The payment is only authorised and the plugin captures it when the configured delivery state is reached.
     */
    case ON_SHIPMENT = 'on_shipment';

    /**
     * @return bool True if the payment is only authorised and Adyen does not capture it by itself.
     */
    public function isManualCapture(): bool
    {
        return $this === self::MANUAL || $this === self::ON_SHIPMENT;
    }

    /**
     * @return bool True if the payment is not captured at authorisation, so the transaction stays authorised
     *              and the payment can be captured from the shop.
     */
    public function isDeferredCapture(): bool
    {
        return $this->isManualCapture() || $this === self::DELAYED;
    }

    /**
     * @param mixed $value Raw configuration value.
     *
     * @return self|null Null for empty or unknown values.
     */
    public static function tryFromConfig(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
