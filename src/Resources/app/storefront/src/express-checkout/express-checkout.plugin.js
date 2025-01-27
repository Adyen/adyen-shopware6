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
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';
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
        this.email = '';
        this.pspReference = '';
        this.paymentData = null;
        this.blockPayPalShippingOptionChange = false;

        let onPaymentDataChanged = (intermediatePaymentData) => {
            return new Promise(async resolve => {
                try {
                    const {callbackTrigger, shippingAddress, shippingOptionData} = intermediatePaymentData;
                    const paymentDataRequestUpdate = {};

                    if (callbackTrigger === 'INITIALIZE' || callbackTrigger === 'SHIPPING_ADDRESS') {

                        const extraData = {};

                        if (shippingAddress) {
                            this.newAddress = extraData.newAddress = shippingAddress;
                        }

                        extraData.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.googlepay;

                        const response = await this.fetchExpressCheckoutConfig(adyenExpressCheckoutOptions.expressCheckoutConfigUrl, extraData);

                        const shippingMethodsArray = response.shippingMethodsResponse;
                        const newShippingMethodsArray = [];
                        shippingMethodsArray.forEach((shippingMethod) => {
                            newShippingMethodsArray.push(
                                {
                                    'id': shippingMethod['id'],
                                    'label': shippingMethod['label'],
                                    'description': shippingMethod['description'],
                                }
                            )
                        });

                        paymentDataRequestUpdate.newShippingOptionParameters = {
                            defaultSelectedOptionId: newShippingMethodsArray[0].id,
                            shippingOptions: newShippingMethodsArray
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
            let transformedAddress = {
                state: intermediatePaymentData.shippingAddress.administrativeArea,
                zipcode: intermediatePaymentData.shippingAddress.postalCode,
                street: intermediatePaymentData.shippingAddress.address1,
                address2: intermediatePaymentData.shippingAddress.address2,
                address3: intermediatePaymentData.shippingAddress.address3,
                city: intermediatePaymentData.shippingAddress.locality,
                countryCode: intermediatePaymentData.shippingAddress.countryCode,
                firstName: '',
                lastName: ''
            };

            this.email = intermediatePaymentData.email;
            this.newAddress = transformedAddress;
            this.newShippingMethod = intermediatePaymentData.shippingOptionData;
            return new Promise(resolve => {
                resolve({transactionState: "SUCCESS",});
            });
        };

        this.paymentMethodSpecificConfig = {
            "paywithgoogle": {
                onClick: (resolve, reject) => {
                    this.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.googlepay;
                    resolve();
                },
                isExpress: true,
                callbackIntents: !userLoggedIn ? ['SHIPPING_ADDRESS', 'PAYMENT_AUTHORIZATION', 'SHIPPING_OPTION'] : [],
                shippingAddressRequired: !userLoggedIn,
                emailRequired: !userLoggedIn,
                shippingAddressParameters: {
                    allowedCountryCodes: [],
                    phoneNumberRequired: true
                },
                shippingOptionRequired: !userLoggedIn,
                buttonSizeMode: "fill",
                onAuthorized: paymentData => {
                },
                buttonColor: "white",
                paymentDataCallbacks: !userLoggedIn ?
                    {
                        onPaymentDataChanged: onPaymentDataChanged,
                        onPaymentAuthorized: onPaymentAuthorized
                    } :
                    {}
            },
            "googlepay": {},
            "paypal": {
                isExpress: true,
                onShopperDetails: this.onShopperDetails.bind(this),
                blockPayPalCreditButton: true,
                blockPayPalPayLaterButton: true,
                onShippingAddressChange: this.onShippingAddressChanged.bind(this),
                onShippingOptionsChange: this.onShippingOptionsChange.bind(this),
                onCancel: (data, component) => {
                    ElementLoadingIndicatorUtil.remove(document.body);
                    adyenConfiguration.componentsWithPayButton['paypal'].onCancel(data, component, this);
                },
                onError: (error, component) => {
                    if (error.name === 'CANCEL') {
                        this._client.post(
                            `${adyenExpressCheckoutOptions.cancelOrderTransactionUrl}`,
                            JSON.stringify({orderId: this.orderId})
                        );
                    }

                    ElementLoadingIndicatorUtil.remove(document.body);
                    adyenConfiguration.componentsWithPayButton['paypal'].onError(error, component, this);
                    console.log(error);
                }
            }
        };

        // if(!userLoggedIn){
        //     this.paymentMethodSpecificConfig.applepay = {
        //         isExpress: true,
        //         requiredBillingContactFields: ['postalAddress'],
        //         requiredShippingContactFields: ['postalAddress', 'name', 'phoneticName', 'phone', 'email'],
        //         onAuthorized: this.onAuthorized.bind(this),
        //         onShippingContactSelected: this.onShippingContactSelected.bind(this),
        //     }
        // }

        this.quantityInput = document.querySelector('.product-detail-quantity-select') ||
            document.querySelector('.product-detail-quantity-input');

        this.listenOnQuantityChange();

        this.mountExpressCheckoutComponents({
            countryCode: adyenExpressCheckoutOptions.countryCode,
            amount: adyenExpressCheckoutOptions.amount,
            currency: adyenExpressCheckoutOptions.currency,
            paymentMethodsResponse: JSON.parse(adyenExpressCheckoutOptions.paymentMethodsResponse)
        });

    }

    async fetchExpressCheckoutConfig(url, extraData = {}) {
        const productMeta = document.querySelector('meta[itemprop="productID"]');
        const productId = productMeta ? productMeta.content : '-1';

        return new Promise((resolve, reject) => {
            this._client.post(
                url,
                JSON.stringify({
                    quantity: this.quantityInput ? this.quantityInput.value : -1,
                    productId: productId,
                    ...extraData
                }),
                (response) => {
                    try {
                        const parsedResponse = JSON.parse(response);
                        console.log('parsedResponse')

                        if (this._client._request.status >= 400) {
                            // if valid resonse, but contains error data
                            reject({
                                error: parsedResponse.error
                            });

                            console.log('>=400')

                            return;
                        }

                        resolve(parsedResponse);
                    } catch (error) {

                        console.log('catch')

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
            if (availableTypes.includes(type)) {
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
            countryCode: data.countryCode,
            amount: {
                value: data.amount,
                currency: data.currency,
            },
            paymentMethodsResponse: data.paymentMethodsResponse,
            onAdditionalDetails: this.handleOnAdditionalDetails.bind(this),
            onSubmit: function (state, component) {
                if (!state.isValid) {
                    return;
                }

                const productMeta = document.querySelector('meta[itemprop="productID"]');
                const productId = productMeta ? productMeta.content : '-1';
                const quantity = this.quantityInput ? this.quantityInput.value : -1;
                const type = state.data.paymentMethod.type;
                if (type === 'paypal') {
                    this.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.paypal;
                    const paypalComponentConfig = adyenConfiguration.componentsWithPayButton['paypal'];
                    if ('responseHandler' in paypalComponentConfig) {
                        this.responseHandler = paypalComponentConfig.responseHandler.bind(component, this);
                    }
                }
                if (type === 'applepay') {
                    this.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.applepay;
                }

                const requestData = {
                    productId: productId,
                    quantity: quantity,
                    formattedHandlerIdentifier: this.formattedHandlerIdentifier,
                    newAddress: this.newAddress,
                    newShippingMethod: this.newShippingMethod,
                    email: this.email,
                    affiliateCode: adyenExpressCheckoutOptions.affiliateCode,
                    campaignCode: adyenExpressCheckoutOptions.campaignCode
                };

                let extraParams = {
                    stateData: JSON.stringify(state.data)
                };

                this.createOrder(JSON.stringify(requestData), extraParams);
            }.bind(this)
        };

        return Promise.resolve(await AdyenCheckout(ADYEN_EXPRESS_CHECKOUT_CONFIG));
    }

    createOrder(requestData, extraParams) {
        this._client.post(
            adyenExpressCheckoutOptions.checkoutOrderExpressUrl,
            requestData,
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

        if (order.url) {
            location.href = order.url;

            return;
        }

        this.orderId = order.id;
        this.finishUrl = new URL(
            location.origin + adyenExpressCheckoutOptions.paymentFinishUrl);
        this.finishUrl.searchParams.set('orderId', this.orderId);
        this.errorUrl = new URL(
            location.origin + adyenExpressCheckoutOptions.paymentErrorUrl);
        this.errorUrl.searchParams.set('orderId', this.orderId);

        let customerId = '';
        if (order.customerId) {
            customerId = order.customerId;
        }

        let params = {
            'orderId': this.orderId,
            'finishUrl': this.finishUrl.toString(),
            'errorUrl': this.errorUrl.toString(),
            'customerId': customerId
        };

        // Append any extra parameters passed, e.g. stateData
        for (const property in extraParams) {
            params[property] = extraParams[property];
        }

        this._client.post(
            adyenExpressCheckoutOptions.paymentHandleExpressUrl,
            JSON.stringify(params),
            this.afterPayOrder.bind(this, this.orderId),
        );
    }

    afterPayOrder(orderId, response) {
        try {
            response = JSON.parse(response);
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
        try {
            const paymentResponse = JSON.parse(response);
            if (paymentResponse.isFinal || paymentResponse.action.type === 'voucher') {
                location.href = this.returnUrl;
            }
        } catch (e) {
            console.log(e);
        }
    }

    handleOnAdditionalDetails(state) {
        this._client.post(
            `${adyenExpressCheckoutOptions.paymentDetailsUrl}`,
            JSON.stringify({
                orderId: this.orderId,
                stateData: JSON.stringify(state.data),
                newAddress: this.newAddress,
                newShipping: this.newShippingMethod,
            }),
            function (paymentResponse) {
                if (this._client._request.status !== 200) {
                    location.href = this.errorUrl.toString();
                    return;
                }

                this.responseHandler(paymentResponse);
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

    afterQuantityUpdated(response) {
        try {
            const responseObject = JSON.parse(response);
            this.mountExpressCheckoutComponents({
                countryCode: responseObject.countryCode,
                amount: responseObject.amount,
                currency: responseObject.currency,
                paymentMethodsResponse: JSON.parse(responseObject.paymentMethodsResponse),
            })
        } catch (e) {
            window.location.reload();
        }
    }

    async onShippingAddressChanged(data, actions, component) {
        this.blockPayPalShippingOptionChange = false;
        this.newShippingMethod = {};
        const currentPaymentData = component.paymentData;
        const shippingAddress = data.shippingAddress;

        const extraData = this.getDataForPayPalCallbacks();
        extraData.currentPaymentData = currentPaymentData;

        if (shippingAddress) {
            this.newAddress = extraData.newAddress = shippingAddress;
        }

        try {
            const response = await new Promise((resolve, reject) => {
                this._client.post(
                    `${adyenExpressCheckoutOptions.expressCheckoutUpdatePaypalOrderUrl}`,
                    JSON.stringify(extraData),
                    function (response) {
                        try {
                            const responseObject = JSON.parse(response);

                            if (!responseObject || this._client._request.status !== 200) {
                                reject(new Error('Server error or invalid response'));
                            } else {
                                resolve(responseObject);
                            }
                        } catch (error) {
                            reject(error);
                        }
                    }.bind(this)
                );
            });

            component.updatePaymentData(response.paymentData);
        } catch (error) {
            this.blockPayPalShippingOptionChange = true;
            return actions.reject(data.errors.COUNTRY_ERROR);
        }
    }

    async onShippingOptionsChange(data, actions, component) {
        if(this.blockPayPalShippingOptionChange === true){
            return actions.reject(data.errors.METHOD_UNAVAILABLE);
        }

        const currentPaymentData = component.paymentData;
        const selectedShippingOption = data.selectedShippingOption;

        const extraData = this.getDataForPayPalCallbacks();
        extraData.currentPaymentData = currentPaymentData;

        if (selectedShippingOption) {
            this.newShippingMethod = extraData.newShippingMethod = selectedShippingOption;
        }

        try {
            const response = await new Promise((resolve, reject) => {
                this._client.post(
                    `${adyenExpressCheckoutOptions.expressCheckoutUpdatePaypalOrderUrl}`,
                    JSON.stringify(extraData),
                    function (response) {
                        try {
                            const responseObject = JSON.parse(response);

                            if (!responseObject || this._client._request.status !== 200) {
                                reject(new Error('onShippingOptionsChange Server error or invalid response'));
                            } else {
                                resolve(responseObject);
                            }
                        } catch (error) {
                            reject(error);
                        }
                    }.bind(this)
                );
            });

            component.updatePaymentData(response.paymentData);
        } catch (error) {
            return actions.reject(data.errors.METHOD_UNAVAILABLE);
        }
    }

    onShopperDetails(shopperDetails, rawData, actions) {
        this.newAddress = {
            firstName: shopperDetails.shopperName.firstName,
            lastName: shopperDetails.shopperName.lastName,
            street: shopperDetails.shippingAddress.street,
            postalCode: shopperDetails.shippingAddress.postalCode,
            city: shopperDetails.shippingAddress.city,
            countryCode: shopperDetails.shippingAddress.country,
            phoneNumber: shopperDetails.telephoneNumber,
            email: shopperDetails.shopperEmail
        }

        actions.resolve();
    }

    getDataForPayPalCallbacks() {
        const extraData = {};

        extraData.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.paypal;
        extraData.pspReference = this.pspReference;
        extraData.orderId = this.orderId;

        return extraData;
    }

    async onShippingContactSelected(resolve, reject, event) {
        console.log('onShippingContactSelected')
        const shippingContact = event.payment.shippingContact;
        const shippingAddress = {
            firstName: shippingContact.givenName,
            lastName: shippingContact.familyName,
            street: shippingContact.addressLines.length > 0 ? shippingContact.addressLines[0] : '',
            city: shippingContact.locality,
            state: shippingContact.administrativeArea,
            country: shippingContact.countryCode,
            zipCode: shippingContact.postalCode,
            phone: shippingContact.phoneNumber,
        };

        const extraData = {};

        if (shippingAddress) {
            this.newAddress = extraData.newAddress = shippingAddress;
        }

        extraData.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.googlepay;

        console.log('onShippingContactSelected before request')

        const response = await this.fetchExpressCheckoutConfig(adyenExpressCheckoutOptions.expressCheckoutConfigUrl, extraData);

        console.log('onShippingContactSelected response: ' + JSON.stringify(response));


        let amount = 0;
        let applePayShippingMethodUpdate = {};

        if(response){
            console.log('onShippingContactSelected success!');

            amount = parseInt(response.amount) / 100;

            applePayShippingMethodUpdate.newTotal = {
                type: 'final',
                label: 'Total amount',
                amount: (amount).toString()
            };

            resolve(applePayShippingMethodUpdate);

            return;
        }


        console.log('onShippingContactSelected NOT success!');

        let update = {
            newTotal: {
                type: 'final',
                label: 'Total amount',
                amount: (amount).toString()
            },
            errors: [new ApplePayError(
                'shippingContactInvalid',
                'countryCode',
                'Error message')
            ]
        };
        resolve(update);


        // const shippingMethodsArray = response.shippingMethodsResponse;
        // const newShippingMethodsArray = [];
        // shippingMethodsArray.forEach((shippingMethod) => {
        //     newShippingMethodsArray.push(
        //         {
        //             'id': shippingMethod['id'],
        //             'label': shippingMethod['label'],
        //             'description': shippingMethod['description'],
        //         }
        //     )
        // });
        //
        // applePayShippingMethodUpdate.newShippingOptionParameters = {
        //     defaultSelectedOptionId: newShippingMethodsArray[0].id,
        //     shippingOptions: newShippingMethodsArray
        // };

    }
}