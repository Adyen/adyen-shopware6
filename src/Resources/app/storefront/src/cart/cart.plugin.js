/*
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
 */

import Plugin from 'src/plugin-system/plugin.class';
import StoreApiClient from 'src/service/store-api-client.service';

export default class CartPlugin extends Plugin {
    init() {
        this._client = new StoreApiClient();
        this.adyenCheckout = Promise;
        this.initializeCheckoutComponent().bind(this);
    }

    async initializeCheckoutComponent () {
        const { locale, clientKey, environment } = adyenCheckoutConfiguration;
        const giftcards = JSON.parse(adyenGiftcardsConfiguration.giftcards);

        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment
        };

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
    }

    mountGiftcardComponent (giftcardData) {

    }
}
