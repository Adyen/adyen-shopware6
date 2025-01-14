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
import adyenConfiguration from '../configuration/adyen';

export default class ExpressCheckoutPlugin extends Plugin {
    init() {
        this._client = new HttpClient();
        this.paymentMethodInstance = null;
        this.responseHandler = this.handlePaymentAction;

        const userLoggedIn = adyenExpressCheckoutOptions.userLoggedIn === "true";
        this.formattedHandlerIdentifier = '';
        this.newAddress = {};
        this.newShippingMethod = {};

        let onPaymentDataChanged = (intermediatePaymentData) => {
            console.log("onPaymentDataChanged triggered", intermediatePaymentData);
            return new Promise(async  resolve => {
                try {
                    const {callbackTrigger, shippingAddress, shippingOptionData} = intermediatePaymentData;
                    const paymentDataRequestUpdate = {};

                    if (callbackTrigger === 'INITIALIZE' || callbackTrigger === 'SHIPPING_ADDRESS') {
                        console.log("ADDRESS trigger");

                        const extraData = {};

                        if (shippingAddress) {
                            this.newAddress = extraData.newAddress = shippingAddress;
                        }

                        extraData.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.googlepay;

                        const response = await this.fetchExpressCheckoutConfig(adyenExpressCheckoutOptions.expressCheckoutConfigUrl, extraData);

                        let shippingMethodsArray = response.shippingMethodsResponse;

                        paymentDataRequestUpdate.newShippingOptionParameters = {
                            defaultSelectedOptionId: shippingMethodsArray[0].id,
                            shippingOptions: shippingMethodsArray
                        };

                        paymentDataRequestUpdate.newTransactionInfo = {
                            currencyCode: response.currency,
                            totalPriceStatus: "FINAL",
                            totalPrice: (parseInt(response.amount) / 100).toString(),
                            totalPriceLabel: "Total",
                            countryCode: response.countryCode,
                        };
                    }

                    if (callbackTrigger === 'SHIPPING_OPTION') {
                        console.log("SHIPPING trigger")

                        const extraData = {};

                        if (shippingAddress) {
                            this.newAddress = extraData.newAddress = shippingAddress;
                        }

                        if (shippingOptionData) {
                            this.newShippingMethod = extraData.newShippingMethod = shippingOptionData;
                        }

                        extraData.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.googlepay;

                        const response = await this.fetchExpressCheckoutConfig(adyenExpressCheckoutOptions.expressCheckoutConfigUrl, extraData);

                        paymentDataRequestUpdate.newTransactionInfo = {
                            currencyCode: response.currency,
                            totalPriceStatus: "FINAL",
                            totalPrice: (parseInt(response.amount) / 100).toString(),
                            totalPriceLabel: "Total",
                            countryCode: response.countryCode,
                        };
                    }

                    resolve(paymentDataRequestUpdate);
                } catch (error) {
                    console.error("Error in onPaymentDataChanged:", error);
                    resolve({
                        error: error.error
                    });
                }
            });
        };

        let onPaymentAuthorized = (intermediatePaymentData) => {
            console.log("onPaymentAuthorized triggered", intermediatePaymentData);
            return new Promise(resolve => {
                resolve({transactionState: "SUCCESS",});
            });
        };

        this.paymentMethodSpecificConfig = {
            "paywithgoogle": {
                onClick: (resolve, reject) => {
                    console.log("Google Pay button clicked!");
                    console.log(userLoggedIn)
                    this.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.googlepay;
                    resolve();
                },
                isExpress: true,
                callbackIntents: !userLoggedIn ? ['SHIPPING_ADDRESS', 'PAYMENT_AUTHORIZATION', 'SHIPPING_OPTION'] : [],
                shippingAddressRequired: !userLoggedIn ,
                emailRequired: !userLoggedIn ,
                shippingAddressParameters: {
                    allowedCountryCodes: [],
                    phoneNumberRequired: true
                },
                shippingOptionRequired: !userLoggedIn,
                buttonSizeMode: "fill",
                onAuthorized: paymentData => {
                    console.log('Shopper details', paymentData);
                },
                buttonColor : "white",
                paymentDataCallbacks: !userLoggedIn ?
                    {
                        onPaymentDataChanged: onPaymentDataChanged,
                        onPaymentAuthorized: onPaymentAuthorized
                    } :
                    {}
            },
            "googlepay": {},
            "paypal": {},
            "applepay": {}
        };

        this.quantityInput = document.querySelector('.product-detail-quantity-select') ||
            document.querySelector('.product-detail-quantity-input');

        if(this.quantityInput) {
            console.log("kolicina" + this.quantityInput.value)
        }

        this.listenOnQuantityChange();

        console.log(adyenExpressCheckoutOptions);

        this.mountExpressCheckoutComponents({
            countryCode: adyenExpressCheckoutOptions.countryCode,
            amount:  adyenExpressCheckoutOptions.amount,
            currency: adyenExpressCheckoutOptions.currency,
            paymentMethodsResponse: JSON.parse(adyenExpressCheckoutOptions.paymentMethodsResponse),
            // shippingMethodsResponse: adyenExpressCheckoutOptions.paymentMethodsResponse,
        });

    }

    async fetchExpressCheckoutConfig(url, extraData = {}) {
        const productMeta = document.querySelector('meta[itemprop="productID"]');
        const productId = productMeta ? productMeta.content : '-1';

        return new Promise((resolve, reject) => {
            this._client.post(
                url,
                JSON.stringify({
                    quantity:  this.quantityInput ? this.quantityInput.value : -1,
                    productId: productId,
                    ...extraData
                }),
                (response) => {
                    try {
                        const parsedResponse = JSON.parse(response);
                        console.log("odgovor ")
                        console.log(parsedResponse);

                        console.log("status ")
                        console.log(this._client._request.status)

                        if (this._client._request.status >= 400) {
                            // if valid resonse, but contains error data
                            reject({
                                error: parsedResponse.error
                            });
                            return;
                        }

                        resolve(parsedResponse);
                    } catch (error) {
                        reject({
                            status: 500,
                            message: "Failed to parse server response.",
                        });
                    }
                }
            );
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

        let availableTypes = [];
        let paymentMethods = data.paymentMethodsResponse.paymentMethods || [];
        for (let i = 0; i < paymentMethods.length; i++) {
            availableTypes[i] = paymentMethods[i].type;
        }

        for (let i = 0; i < checkoutElements.length; i++) {
            let type = checkoutElements[i].getElementsByClassName('adyen-type')[0].value;
            if (availableTypes.includes(type)){
                this.initializeCheckoutComponent(data).then(function (checkoutInstance) {
                    this.mountElement(type, checkoutInstance, checkoutElements[i]);
                }.bind(this));
            }
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
            onSubmit: function (state, component) {
                console.log("on submit fja")
                console.log(state);
                const productMeta = document.querySelector('meta[itemprop="productID"]');
                const productId = productMeta ? productMeta.content : '-1';
                const quantity =  this.quantityInput ? this.quantityInput.value : -1;
                // SUBMIT PAYMENT
                // check what method -gp, ap, pp
                const formData = new FormData();
                formData.append('productId', productId);
                formData.append('quantity', quantity);
                formData.append('formattedHandlerIdentifier', this.formattedHandlerIdentifier);
                formData.append('newAddress', JSON.stringify(this.newAddress));
                formData.append('newShippingMethod', JSON.stringify(this.newShippingMethod));
                let extraParams = {
                    stateData: JSON.stringify(state.data)
                };

                // Ispis sadržaja FormData
                for (let [key, value] of formData.entries()) {
                    console.log(`${key}: ${value}`);
                }


                this.createOrder(formData, extraParams);
                //this.afterCreateOrder({},{});
            }.bind(this)
        };

        return Promise.resolve(await AdyenCheckout(ADYEN_EXPRESS_CHECKOUT_CONFIG));
    }

    createOrder(formData, extraParams) {
        console.log("usao u create order")
        this._client.post(
            adyenExpressCheckoutOptions.checkoutOrderExpressUrl,
            formData,
            this.afterCreateOrder.bind(this, extraParams)
        );
    }

    afterCreateOrder(extraParams = {}, response) {
        let order;
        try {
            order = JSON.parse(response);
        } catch (error) {
            ElementLoadingIndicatorUtil.remove(document.body);
            console.log('Error: invalid response from Shopware API', response);
            return;
        }

        if(order.url){
            location.href = order.url;

            return;
        }

        console.log("usao ovdeee")

        this.orderId = order.id;
        this.finishUrl = new URL(
            location.origin + adyenExpressCheckoutOptions.paymentFinishUrl);
        this.finishUrl.searchParams.set('orderId', this.orderId);
        this.errorUrl = new URL(
            location.origin + adyenExpressCheckoutOptions.paymentErrorUrl);
        this.errorUrl.searchParams.set('orderId', this.orderId );

        let params = {
            'orderId': this.orderId,
            'finishUrl': this.finishUrl.toString(),
            'errorUrl': this.errorUrl.toString(),
        };
        console.log("params" + params);

        console.log("parametri")
        console.log(extraParams)
        // Append any extra parameters passed, e.g. stateData
        for (const property in extraParams) {
            params[property] = extraParams[property];
        }
        console.log("saljem")

        this._client.post(
            adyenExpressCheckoutOptions.paymentHandleExpressUrl,
            JSON.stringify(params),
            this.afterPayOrder.bind(this, this.orderId),
        );
    }
    afterPayOrder(orderId, response) {
        console.log("after")
        try {
            response = JSON.parse(response);
            console.log(response)
            this.returnUrl = response.redirectUrl;
        } catch (e) {
            ElementLoadingIndicatorUtil.remove(document.body);
            console.log('Error: invalid response from Shopware API', response);
            return;
        }

        // If payment call returns the errorUrl, then no need to proceed further.
        // Redirect to error page.
        if (this.returnUrl === this.errorUrl.toString()) {
            location.href = this.returnUrl;
        }

        try {
            this._client.post(
                `${adyenExpressCheckoutOptions.paymentStatusUrl}`,
                JSON.stringify({'orderId': orderId}),
                this.responseHandler.bind(this),
            );
        } catch (e) {
            console.log(e);
        }
    }

    handlePaymentAction(response) {
        console.log("hendluje")
        try {
            const paymentResponse = JSON.parse(response);
            console.log(paymentResponse)
            if (paymentResponse.isFinal || paymentResponse.action.type === 'voucher') {
                location.href = this.returnUrl;
            }
            // if (!!paymentResponse.action) {
            //     const actionModalConfiguration = {};
            //     if (paymentResponse.action.type === 'threeDS2') {
            //         actionModalConfiguration.challengeWindowSize = '05';
            //     }
            //
            //     this.adyenCheckout
            //         .createFromAction(paymentResponse.action, actionModalConfiguration)
            //         .mount('[data-adyen-payment-action-container]');
            //     const modalActionTypes = ['threeDS2', 'qrCode']
            //     if (modalActionTypes.includes(paymentResponse.action.type)) {
            //         if (window.jQuery) {
            //             // Bootstrap v4 support
            //             $('[data-adyen-payment-action-modal]').modal({show: true});
            //         } else {
            //             // Bootstrap v5 support
            //             var adyenPaymentModal = new bootstrap.Modal(document.getElementById('adyen-payment-action-modal'), {
            //                 keyboard: false
            //             });
            //             adyenPaymentModal.show();
            //         }
            //     }
            // }
        } catch (e) {
            console.log(e);
        }
    }

    handleOnAdditionalDetails(state) {
        console.log("aasdasdasdad")
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
        if (this.quantityInput) {
            this.quantityInput.addEventListener('change', (event) => {
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