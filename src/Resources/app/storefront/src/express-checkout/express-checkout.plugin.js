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

        this.userLoggedIn = adyenExpressCheckoutOptions.userLoggedIn === "true";
        this.formattedHandlerIdentifier = '';
        this.newAddress = {};
        this.newShippingMethod = {};
        this.email = '';
        this.pspReference = '';
        this.paymentData = null;
        this.blockPayPalShippingOptionChange = false;
        this.stateData = {};

        let onPaymentDataChanged = (intermediatePaymentData) => {
            return new Promise(async resolve => { //NOSONAR
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
                callbackIntents: !this.userLoggedIn ? ['SHIPPING_ADDRESS', 'PAYMENT_AUTHORIZATION', 'SHIPPING_OPTION'] : [],
                shippingAddressRequired: !this.userLoggedIn,
                emailRequired: !this.userLoggedIn,
                shippingAddressParameters: {
                    allowedCountryCodes: [],
                    phoneNumberRequired: true
                },
                shippingOptionRequired: !this.userLoggedIn,
                buttonSizeMode: "fill",
                onAuthorized: (paymentData,actions) => {
                    actions.resolve();
                },
                buttonColor: "white",
                paymentDataCallbacks: !this.userLoggedIn ?
                    {
                        onPaymentDataChanged: onPaymentDataChanged,
                        onPaymentAuthorized: onPaymentAuthorized
                    } :
                    {}
            }
        };

        if (!this.userLoggedIn) {
            this.paymentMethodSpecificConfig.paypal = {
                isExpress: true,
                onAuthorized: this.onShopperDetails.bind(this),
                blockPayPalCreditButton: true,
                blockPayPalPayLaterButton: true,
                onShippingAddressChange: this.onShippingAddressChanged.bind(this),
                onShippingOptionsChange: this.onShippingOptionsChange.bind(this),
            };

            this.paymentMethodSpecificConfig.applepay = {
                isExpress: true,
                requiredBillingContactFields: ['postalAddress'],
                requiredShippingContactFields: ['postalAddress', 'name', 'phoneticName', 'phone', 'email'],
                onAuthorized: this.handleApplePayAuthorization.bind(this),
                onShippingContactSelected: this.onShippingContactSelected.bind(this),
                onShippingMethodSelected: this.onShippingMethodSelected.bind(this),
            };
        }

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
            if (availableTypes.includes(type)) {
                this.initializeCheckoutComponent(data).then(function (checkoutInstance) {
                    this.mountElement(type, checkoutInstance, checkoutElements[i]);
                }.bind(this));
            }
        }
    }

    mountElement(paymentType, checkoutInstance, mountElement) {
        let paymentMethodConfig = this.paymentMethodSpecificConfig[paymentType] || null;

        let selectedPaymentMethodObject = (checkoutInstance.paymentMethodsResponse.paymentMethods
            .filter(item => item.type === paymentType))[0];

        if (paymentMethodConfig && selectedPaymentMethodObject && selectedPaymentMethodObject.configuration) {
            paymentMethodConfig.configuration = selectedPaymentMethodObject.configuration;
        }

        // If there is applepay specific configuration then set country code to configuration
        if ('applepay' === paymentType &&
            paymentMethodConfig) {
            paymentMethodConfig.countryCode = checkoutInstance.options.countryCode;
        }

        if ((paymentType === "paywithgoogle" || paymentType === "googlepay")
            && (adyenExpressCheckoutOptions.googleMerchantId !== "" && adyenExpressCheckoutOptions.gatewayMerchantId !== "")) {
            paymentMethodConfig.configuration = {
                merchantId: adyenExpressCheckoutOptions.googleMerchantId,
                gatewayMerchantId: adyenExpressCheckoutOptions.gatewayMerchantId
            };
        }

        let PaymentMethodClass = Object.values(AdyenWeb).find(cls => {
            if (!cls) {
                return false;
            }
            if (cls.type === paymentType) {
                return true;
            }
            if (Array.isArray(cls.txVariants) && cls.txVariants.includes(paymentType)) {
                return true;
            }
            return false;
        });

        if (!PaymentMethodClass) {
            console.log(`Payment method "${selectedPaymentMethodObject.type}" is not available.`);
            return false;
        }

        const paymentMethodInstance = new PaymentMethodClass(checkoutInstance, paymentMethodConfig);
        paymentMethodInstance.mount(mountElement);
    }

    async initializeCheckoutComponent(data) {
        const { AdyenCheckout } = window.AdyenWeb;
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
            onError: (error, component) => {
                let componentWithPayButton = adyenConfiguration.componentsWithPayButton['googlepay'];
                if (component.props.name === 'PayPal' && error.name === 'CANCEL') {
                    this._client.post(
                        `${adyenExpressCheckoutOptions.cancelOrderTransactionUrl}`,
                        JSON.stringify({orderId: this.orderId})
                    );

                    componentWithPayButton = adyenConfiguration.componentsWithPayButton['paypal'];
                }

                ElementLoadingIndicatorUtil.remove(document.body);
                componentWithPayButton.onError(error, component, this);
                console.log(error);
            },
            onSubmit: function (state, component) {
                if (!state.isValid) {
                    return;
                }

                const type = state.data.paymentMethod.type;
                if (type === 'applepay') {
                    if(!this.userLoggedIn){
                        this.stateData = state.data;

                        return;
                    }

                    this.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.applepay;
                }

                const productMeta = document.querySelector('meta[itemprop="productID"]');
                const productId = productMeta ? productMeta.content : '-1';
                const quantity = this.quantityInput ? this.quantityInput.value : -1;

                if (type === 'paypal') {
                    this.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.paypal;
                    const paypalComponentConfig = adyenConfiguration.componentsWithPayButton['paypal'];
                    if ('responseHandler' in paypalComponentConfig) {
                        this.responseHandler = paypalComponentConfig.responseHandler.bind(component, this);
                    }
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

    // Callback for PayPal payment method
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

        return new Promise((resolve, reject) => {
            this._client.post(
                `${adyenExpressCheckoutOptions.expressCheckoutUpdatePaypalOrderUrl}`,
                JSON.stringify(extraData),
                function (response) {
                    try {
                        const responseObject = JSON.parse(response);

                        if (!responseObject || this._client._request.status !== 200) {
                            this.blockPayPalShippingOptionChange = true;
                            reject(data.errors.COUNTRY_ERROR);
                        } else {
                            component.updatePaymentData(responseObject.paymentData);
                            resolve();
                        }
                    } catch (error) {
                        this.blockPayPalShippingOptionChange = true;
                        reject(data.errors.COUNTRY_ERROR);
                    }
                }.bind(this)
            );
        });
    }

    // Callback for PayPal payment method
    async onShippingOptionsChange(data, actions, component) {
        if (this.blockPayPalShippingOptionChange === true) {
            return actions.reject(data.errors.METHOD_UNAVAILABLE);
        }

        const currentPaymentData = component.paymentData;
        const selectedShippingOption = data.selectedShippingOption;

        const extraData = this.getDataForPayPalCallbacks();
        extraData.currentPaymentData = currentPaymentData;

        if (selectedShippingOption) {
            this.newShippingMethod = extraData.newShippingMethod = selectedShippingOption;
        }

        return new Promise((resolve, reject) => {
            this._client.post(
                `${adyenExpressCheckoutOptions.expressCheckoutUpdatePaypalOrderUrl}`,
                JSON.stringify(extraData),
                function (response) {
                    try {
                        const responseObject = JSON.parse(response);

                        if (!responseObject || this._client._request.status !== 200) {
                            reject(data.errors.METHOD_UNAVAILABLE);
                        } else {
                            component.updatePaymentData(responseObject.paymentData);
                            resolve();
                        }
                    } catch (error) {
                        reject(data.errors.METHOD_UNAVAILABLE);
                    }
                }.bind(this)
            );
        });
    }

    // Callback for PayPal payment method
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

    // Callback for ApplePay payment method
    handleApplePayAuthorization(resolve, reject, event) {
        let shippingContact = event.payment.shippingContact;
        const shippingAddress = {
            firstName: shippingContact.givenName,
            lastName: shippingContact.familyName,
            street: shippingContact.addressLines.length > 0 ? shippingContact.addressLines[0] : '',
            zipcode: shippingContact.postalCode,
            city: shippingContact.locality,
            countryCode: shippingContact.countryCode,
            phoneNumber: shippingContact.phoneNumber,
            email: shippingContact.emailAddress
        }

        if (shippingAddress) {
            this.newAddress = shippingAddress;
            this.email = shippingAddress.email
        }

        this.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.applepay;

        const productMeta = document.querySelector('meta[itemprop="productID"]');
        const productId = productMeta ? productMeta.content : '-1';
        const quantity = this.quantityInput ? this.quantityInput.value : -1;

        let extraParams = {
            stateData: JSON.stringify(this.stateData)
        };

        const requestData = {
            productId: productId,
            quantity: quantity,
            formattedHandlerIdentifier: this.formattedHandlerIdentifier,
            newAddress: this.newAddress,
            newShippingMethod: this.newShippingMethod,
            affiliateCode: adyenExpressCheckoutOptions.affiliateCode,
            campaignCode: adyenExpressCheckoutOptions.campaignCode,
            email: this.email
        };

        this.createOrder(JSON.stringify(requestData), extraParams);
    }

    // Callback for ApplePay payment method
    async onShippingContactSelected(resolve, reject, event) {
        const address = event.shippingContact;
        const shippingAddress = {
            firstName: 'Temp',
            lastName: 'Temp',
            street: 'Street 123',
            city: address.locality,
            state: address.administrativeArea,
            countryCode: address.countryCode,
            postalCode: address.postalCode
        };

        const extraData = this.getDataForApplePayCallbacks();

        if (shippingAddress) {
            this.newAddress = extraData.newAddress = shippingAddress;
        }

        this.getApplePayExpressCheckoutConfiguration(resolve, reject, extraData);
    }

    // Callback for ApplePay payment method
    async onShippingMethodSelected(resolve, reject, event) {
        const shippingMethodData = event.shippingMethod;
        const shippingMethod = {
            id: shippingMethodData.identifier,
        };

        const extraData = this.getDataForApplePayCallbacks();

        if (shippingMethod) {
            this.newShippingMethod = extraData.newShippingMethod = shippingMethod;
        }

        this.getApplePayExpressCheckoutConfiguration(resolve, reject, extraData);
    }

    getApplePayExpressCheckoutConfiguration(resolve, reject, extraData){
        let amount = 0;
        let applePayShippingMethodUpdate = {};

        this._client.post(
            adyenExpressCheckoutOptions.expressCheckoutConfigUrl,
            JSON.stringify({
                ...extraData
            }),
            function (response) {
                try {
                    const responseObject = JSON.parse(response);

                    if (!responseObject || this._client._request.status !== 200) {
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
                    } else {
                        amount = parseInt(responseObject.amount) / 100;

                        applePayShippingMethodUpdate.newTotal = {
                            type: 'final',
                            label: 'Total amount',
                            amount: (amount).toString()
                        };

                        const shippingMethodsArray = responseObject.shippingMethodsResponse;
                        const newShippingMethodsArray = [];
                        shippingMethodsArray.forEach((shippingMethod) => {
                            newShippingMethodsArray.push(
                                {
                                    'identifier': shippingMethod['id'],
                                    'label': shippingMethod['label'],
                                    'detail': shippingMethod['description'],
                                    'amount': parseInt(shippingMethod['value']) / 100,
                                    'selected': shippingMethod['selected']
                                }
                            )
                        });

                        applePayShippingMethodUpdate.newShippingMethods = newShippingMethodsArray;
                        resolve(applePayShippingMethodUpdate);
                    }
                } catch (error) {
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
                }
            }.bind(this)
        );
    }

    getDataForApplePayCallbacks() {
        const extraData = {};

        const productMeta = document.querySelector('meta[itemprop="productID"]');
        const productId = productMeta ? productMeta.content : '-1';
        const quantity = this.quantityInput ? this.quantityInput.value : -1;

        extraData.formattedHandlerIdentifier = adyenConfiguration.paymentMethodTypeHandlers.applepay;
        extraData.productId = productId;
        extraData.quantity = quantity;

        return extraData;
    }
}