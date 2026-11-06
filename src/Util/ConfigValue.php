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

namespace Adyen\Shopware\Util;

/**
 * Reads plugin configuration values, which are stored as JSON and may come back as a string or an int.
 */
final class ConfigValue
{
    /**
     * @param mixed $value
     *
     * @return bool True for an int or a string of digits.
     */
    public static function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * @param mixed $value
     *
     * @return int|null The value as an int if it is a whole number above 0, otherwise null.
     */
    public static function toPositiveInt(mixed $value): ?int
    {
        if (!self::isWholeNumber($value)) {
            return null;
        }

        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    /**
     * @param mixed $value
     *
     * @return string|null The value if it is a non-empty string, otherwise null.
     */
    public static function toNonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
