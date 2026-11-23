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
 * Capture modes of the plugin configuration. The mode is stored and passed around as its string value.
 */
final class CaptureMode
{
    /**
     * Payment-method level only: the payment method follows the global capture mode.
     */
    public const GLOBAL = 'global';

    /**
     * Adyen captures the payment immediately.
     */
    public const IMMEDIATE = 'immediate';

    /**
     * Adyen captures the payment automatically after the configured number of days.
     */
    public const DELAYED = 'delayed';

    /**
     * The payment is only authorised and is captured later by the merchant.
     */
    public const MANUAL = 'manual';

    /**
     * The payment is only authorised and the plugin captures it when the configured delivery state is reached.
     */
    public const ON_SHIPMENT = 'on_shipment';

    private const ALL = [
        self::GLOBAL,
        self::IMMEDIATE,
        self::DELAYED,
        self::MANUAL,
        self::ON_SHIPMENT,
    ];

    private function __construct()
    {
    }

    /**
     * @param string $mode
     *
     * @return bool True if the payment is only authorised and Adyen does not capture it by itself.
     */
    public static function isManualCapture(string $mode): bool
    {
        return $mode === self::MANUAL || $mode === self::ON_SHIPMENT;
    }

    /**
     * @param string $mode
     *
     * @return bool True if the payment is not captured at authorisation, so the transaction stays authorised
     *              and the payment can be captured from the shop.
     */
    public static function isDeferredCapture(string $mode): bool
    {
        return self::isManualCapture($mode) || $mode === self::DELAYED;
    }

    /**
     * @param mixed $value Raw configuration value.
     *
     * @return string|null The mode, or null for empty or unknown values.
     */
    public static function tryFromConfig($value): ?string
    {
        return is_string($value) && in_array($value, self::ALL, true) ? $value : null;
    }
}
