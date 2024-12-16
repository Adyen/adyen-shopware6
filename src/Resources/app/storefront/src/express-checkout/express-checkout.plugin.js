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
import HttpClient from 'src/service/http-client.service';

export default class ExpressCheckoutPlugin extends Plugin {
    init() {
        this._client = new HttpClient();
        this.paymentMethodInstance = null;
        this.paymentMethodSpecificConfig = {
            "paywithgoogle": {},
            "googlepay": {},
            "paypal": {},
            "applepay": {}
        };
        this.quantityInput = document.querySelector('.product-detail-quantity-input');
        this.listenOnQuantityChange();
        this.mountExpressCheckoutComponents({
            countryCode: adyenExpressCheckoutOptions.countryCode,
            amount:  adyenExpressCheckoutOptions.amount,
            currency: adyenExpressCheckoutOptions.currency,
            paymentMethodsResponse: JSON.parse(adyenExpressCheckoutOptions.paymentMethodsResponse),
        });

    }

    mountExpressCheckoutComponents(data) {
        if (!document.getElementById('adyen-express-checkout')) {
            return;
        }

        let checkoutElements = document.getElementsByClassName("adyen-express-checkout-element");
        if (checkoutElements.length === 0) {
            return;
        }

        for (let i = 0; i < checkoutElements.length; i++) {
            let type = checkoutElements[i].getElementsByClassName('adyen-type')[0].value;
            this.initializeCheckoutComponent(data).then(function (checkoutInstance) {
                this.mountElement(type, checkoutInstance, checkoutElements[i]);
            }.bind(this));
        }
    }

    mountElement(paymentType, checkoutInstance, mountElement) {
        let paymentMethodConfig = this.paymentMethodSpecificConfig[paymentType] || null;

        // If there is applepay specific configuration then set country code to configuration
        if ('applepay' === paymentType &&
            paymentMethodConfig) {
            paymentMethodConfig.countryCode = checkoutInstance.options.countryCode;
        }

        checkoutInstance.create(
            paymentType,
            paymentMethodConfig
        ).mount(mountElement);
    }

    async initializeCheckoutComponent(data) {
        const {locale, clientKey, environment} = adyenCheckoutConfiguration;

        const ADYEN_EXPRESS_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            showPayButton: true,
            countryCode:data.countryCode,
            amount: {
                value: data.amount,
                currency: data.currency,
            },
            paymentMethodsResponse: data.paymentMethodsResponse,
            onAdditionalDetails: this.handleOnAdditionalDetails.bind(this),
        };

        return Promise.resolve(await AdyenCheckout(ADYEN_EXPRESS_CHECKOUT_CONFIG));
    }


    handleOnAdditionalDetails(state) {
        this._client.post(
            `${adyenExpressCheckoutOptions.paymentDetailsUrl}`,
            JSON.stringify({orderId: this.orderId, stateData: JSON.stringify(state.data)}),
            function (paymentResponse) {
                if (this._client._request.status !== 200) {
                    location.href = this.errorUrl.toString();
                    return;
                }

                // this.responseHandler(paymentResponse);
            }.bind(this)
        );
    }

    listenOnQuantityChange() {
        this.quantityInput?.addEventListener('change', (event) => {
            const newQuantity = event.target.value;
            const productMeta = document.querySelector('meta[itemprop="productID"]');
            const productId = productMeta ? productMeta.content : '-1';
            this._client.post(
                adyenExpressCheckoutOptions.expressCheckoutConfigUrl,
                JSON.stringify({
                    quantity: newQuantity,
                    productId: productId
                }),
                this.afterQuantityUpdated.bind(this)
            );

        });
    }

    afterQuantityUpdated(response){
        try {
            const responseObject = JSON.parse(response);
            this.mountExpressCheckoutComponents({
                countryCode: responseObject.countryCode,
                amount:  responseObject.amount,
                currency: responseObject.currency,
                paymentMethodsResponse: JSON.parse(responseObject.paymentMethodsResponse),
            })
        } catch (e) {
            window.location.reload();
        }
    }
}
