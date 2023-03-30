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

namespace Adyen\Shopware\Handlers;

class CardsPaymentMethodHandler extends AbstractPaymentMethodHandler
{
    const SCHEME = 'scheme';
    const CARD_BRANDS = [
        'mc',
        'visa',
        'amex',
        'discover',
        'maestro',
        'mcstandarddebit',
        'jcb',
        'diners',
        'cup',
        'bcmc',
        'hipercard',
        'elo',
        'troy',
        'dankort',
        'cartebancaire',
        'korean_local_card',
        'amex_applepay',
        'discover_applepay',
        'electron_applepay',
        'elo_applepay',
        'elodebit_applepay',
        'interac_applepay',
        'jcb_applepay',
        'maestro_applepay',
        'mc_applepay',
        'visa_applepay',
        'girocard_applepay'
    ];

    public static function isSchemePayment(string $brand)
    {
        return in_array($brand, self::CARD_BRANDS);
    }

    public static function getPaymentMethodCode()
    {
        return 'scheme';
    }
}
