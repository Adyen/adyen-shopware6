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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */
namespace Adyen\Shopware\Util;

class Idempotency
{
    /**
     * Creates an idempotency key with the given request array.
     *
     * @param array $request Key will be generated based on request array
     * @param array|null $extraData Extra data to provide more data to prevent false duplications
     *
     * @return string
     */
    public function createKeyFromRequest(array $request, array $extraData = null): string
    {
        if (isset($extraData)) {
            $request = array_merge($request, $extraData);
        }

        return hash('sha256', json_encode($request));
    }
}

