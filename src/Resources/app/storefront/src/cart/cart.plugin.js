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
import DomAccess from 'src/helper/dom-access.helper';
import StoreApiClient from 'src/service/store-api-client.service';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

export default class CartPlugin extends Plugin {
    init() {
        this._client = new StoreApiClient();
        this.adyenCheckout = Promise;
        this.initializeCheckoutComponent().then(function () {
            this.listenForGiftcardSelection();
        }.bind(this));
    }

    async initializeCheckoutComponent() {
        const { locale, clientKey, environment } = adyenCheckoutConfiguration;

        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            showPayButton: true
        };

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
    }

    listenForGiftcardSelection() {
        $('.adyen-giftcard').on('click', function (event) {
            ElementLoadingIndicatorUtil.create(DomAccess.querySelector(document, '#adyen-giftcard-component'));
            let payload = JSON.parse(event.currentTarget.dataset.payload);
            this.mountGiftcardComponent(payload);
        }.bind(this));
    }

    mountGiftcardComponent(payload) {
        try {
            const paymentMethodInstance = this.adyenCheckout.create('giftcard', payload);
            paymentMethodInstance.mount('#adyen-giftcard-component');

        } catch (e) {
            console.log('giftcard not available');
        }
        ElementLoadingIndicatorUtil.remove(DomAccess.querySelector(document, '#adyen-giftcard-component'));
    }
}
