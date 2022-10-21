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
        this.paymentMethodInstance = null;
        this.initializeCheckoutComponent().then(function () {
            this.observeGiftcardSelection();
        }.bind(this));
        this.giftcards = adyenGiftcardsConfiguration.giftcards;
        this.giftcardHeader = $('.adyen-giftcard-header');
        this.giftcardComponentClose = $('.adyen-close-giftcard-component');
        this.giftcardComponentClose.on('click', function (event) {
            $(event.currentTarget).hide();
            this.giftcardHeader.html(' ');
            if (this.paymentMethodInstance) {
                this.paymentMethodInstance.unmount();
            }
        }.bind(this));
    }

    async initializeCheckoutComponent() {
        const { locale, clientKey, environment } = adyenCheckoutConfiguration;

        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            amount: {
                currency: adyenGiftcardsConfiguration.currency,
                value: adyenGiftcardsConfiguration.totalPrice,
            }
        };

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
    }

    observeGiftcardSelection() {
        $('.adyen-giftcard').on('click', function (event) {
            if (this.paymentMethodInstance) {
                this.paymentMethodInstance.unmount();
            }
            ElementLoadingIndicatorUtil.create(DomAccess.querySelector(document, '#adyen-giftcard-component'));
            let giftcard = JSON.parse(event.currentTarget.dataset.giftcard)[0];
            this.giftcardHeader.html(giftcard.name);
            this.giftcardComponentClose.show();
            this.mountGiftcardComponent(giftcard);
        }.bind(this));
    }

    mountGiftcardComponent(giftcard) {
        const giftcardConfiguration = Object.assign({}, giftcard, {
            showPayButton: true,
            onBalanceCheck: this.handleBalanceCheck.bind(this),
            onSubmit: this.onGiftcardSubmit.bind(this)
        });
        try {
            this.paymentMethodInstance = this.adyenCheckout.create('giftcard', giftcardConfiguration);
            this.paymentMethodInstance.mount('#adyen-giftcard-component');

        } catch (e) {
            console.log('giftcard not available');
        }
        ElementLoadingIndicatorUtil.remove(DomAccess.querySelector(document, '#adyen-giftcard-component'));
    }

    handleBalanceCheck(resolve, reject, data) {
        this._client.post(
            `${adyenGiftcardsConfiguration.checkBalanceUrl}`,
            JSON.stringify({paymentMethod: data.paymentMethod}),
            function (response) {
                response = JSON.parse(response);
                if ('Success' !== response.resultCode) {
                    console.error(response.resultCode);
                    reject(response.resultCode);
                } else {
                    // 0. compare balance to total amount to be paid
                    const balance = parseFloat(response.balance.value);
                    if (balance >= adyenGiftcardsConfiguration.totalPrice) {
                        // Render pay button for giftcard
                        resolve(response);
                    } else {
                        // 1. create order
                        // 2. make payment request with orderData
                    }
                }
            }.bind(this)
        );
    }

    onGiftcardSubmit(data) {
        ElementLoadingIndicatorUtil.create(document.body);
        this._client.post(
            adyenGiftcardsConfiguration.checkoutOrderUrl,
            new FormData(),
            this.afterCreateOrder.bind(this)
        )
    }

    createAdyenOrder () {

    }

    makePaymentWithGiftcard() {

    }

    cancelOrder() {

    }
}
