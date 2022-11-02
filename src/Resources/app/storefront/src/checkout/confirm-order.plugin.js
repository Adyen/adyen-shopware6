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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import StoreApiClient from 'src/service/store-api-client.service';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';
import adyenConfiguration from '../configuration/adyen';

/* global adyenCheckoutOptions, adyenCheckoutConfiguration, AdyenCheckout */
/* eslint-disable no-unused-vars */
export default class ConfirmOrderPlugin extends Plugin {

    init() {
        this._client = new StoreApiClient();
        this.selectedAdyenPaymentMethod = this.getSelectedPaymentMethodKey();
        this.confirmOrderForm = DomAccess.querySelector(document, '#confirmOrderForm');
        this.confirmFormSubmit = DomAccess.querySelector(document, '#confirmOrderForm button[type="submit"]');
        this.shoppingCartSummaryBlock = $('.checkout-aside-summary-list');
        this.shoppingCartSummaryDetails = null;
        this.minorUnitsQuotient = adyenCheckoutOptions.amount/adyenCheckoutOptions.totalPrice;
        this.giftcardDiscount = (adyenCheckoutOptions.giftcardDiscount / this.minorUnitsQuotient).toFixed(2);
        this.remainingAmount = (adyenCheckoutOptions.totalPrice - this.giftcardDiscount).toFixed(2);
        this.responseHandler = this.handlePaymentAction;
        this.adyenCheckout = Promise;
        this.initializeCheckoutComponent().then(function () {

            // Non adyen payment method selected
            // this can not happen, because this js plugin is registered only if adyen methods selected
            // PluginManager.register('ConfirmOrderPlugin', ConfirmOrderPlugin, '#adyen-payment-checkout-mask');
            if (adyenCheckoutOptions.selectedPaymentMethodPluginId !==
                adyenCheckoutOptions.adyenPluginId) {
                return;
            }

            if (!adyenCheckoutOptions || !adyenCheckoutOptions.paymentStatusUrl ||
                !adyenCheckoutOptions.checkoutOrderUrl || !adyenCheckoutOptions.paymentHandleUrl) {
                console.error('Adyen payment configuration missing.');
                return;
            }

            if (this.selectedAdyenPaymentMethod in adyenConfiguration.componentsWithPayButton) {
                // replaces confirm button with adyen pay button for paywithgoogle, applepay etc.
                this.initializeCustomPayButton();
            }

            if (adyenConfiguration.updatablePaymentMethods.includes(this.selectedAdyenPaymentMethod) && !this.stateData) {
                // create inline component for cards etc. and set event listener for submit button to confirm payment component
                this.renderPaymentComponent(this.selectedAdyenPaymentMethod);
            } else {
                this.confirmFormSubmit.addEventListener('click', this.onConfirmOrderSubmit.bind(this));
            }
        }.bind(this));

        if (parseInt(adyenCheckoutOptions.payInFullWithGiftcard)) {
            if (parseInt(adyenCheckoutOptions.adyenGiftcardSelected)) {
                this.appendGiftcardSummary();
            }
        } else {
            this.appendGiftcardSummary();
        }
    }

    async initializeCheckoutComponent () {
        const { locale, clientKey, environment } = adyenCheckoutConfiguration;
        const paymentMethodsResponse = adyenCheckoutOptions.paymentMethodsResponse;
        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            showPayButton: this.selectedAdyenPaymentMethod in adyenConfiguration.componentsWithPayButton,
            hasHolderName: true,
            paymentMethodsResponse: JSON.parse(paymentMethodsResponse),
            onAdditionalDetails: this.handleOnAdditionalDetails.bind(this),
            countryCode: activeShippingAddress.country,
            paymentMethodsConfiguration: {
                card: {
                    hasHolderName: true,
                }
            },
        };
        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
    }

    handleOnAdditionalDetails (state) {
        this._client.post(
            `${adyenCheckoutOptions.paymentDetailsUrl}`,
            JSON.stringify({orderId: this.orderId, stateData: state.data}),
            function (paymentResponse) {
                if (this._client._request.status !== 200) {
                    location.href = this.errorUrl.toString();
                    return;
                }

                this.responseHandler(paymentResponse);
            }.bind(this)
        );
    }

    onConfirmOrderSubmit(event) {
        const form =  DomAccess.querySelector(document, '#confirmOrderForm');
        if (!form.checkValidity()) {
            return;
        }
        event.preventDefault();
        ElementLoadingIndicatorUtil.create(document.body);
        const formData = FormSerializeUtil.serialize(form);
        this.confirmOrder(formData);
    }

    renderPaymentComponent(type) {
        if (type === 'oneclick') {
            this.renderStoredPaymentMethodComponents();
            return;
        }

        // Get the payment method object from paymentMethodsResponse
        let paymentMethodConfigs = $.grep(this.adyenCheckout.paymentMethodsResponse.paymentMethods, function(paymentMethod) {
            return paymentMethod['type'] === type;
        });
        if (paymentMethodConfigs.length === 0) {
            if (this.adyenCheckout.options.environment === 'test') {
                console.error('Payment method configuration not found. ', type);
            }
            return;
        }
        let paymentMethod = paymentMethodConfigs[0];

        // Mount payment method instance
        this.mountPaymentComponent(paymentMethod, false);
    }

    renderStoredPaymentMethodComponents() {
        // Iterate through and render the stored payment methods
        const storedPaymentMethods = this.adyenCheckout.paymentMethodsResponse.storedPaymentMethods;
        // Mount payment method instance
        storedPaymentMethods.forEach((paymentMethod) => {
            let selector = `[data-adyen-stored-payment-method-id="${paymentMethod.id}"]`;
            this.mountPaymentComponent(paymentMethod, selector, true);
        });

        this.showSelectedStoredPaymentMethod();
        $('[name=adyenStoredPaymentMethodId]').change(this.showSelectedStoredPaymentMethod);
    }

    showSelectedStoredPaymentMethod() {
        // Only show the component for the selected stored payment method
        $('.stored-payment-component').hide();
        let selected = $('[name=adyenStoredPaymentMethodId]:checked').val();
        $(`[data-adyen-stored-payment-method-id="${selected}"]`).show();
    }

    confirmOrder(formData, extraParams= {}) {
        const orderId = adyenCheckoutOptions.orderId;
        if (!!orderId) { //Only used if the order is being edited
            this.updatePayment(formData, orderId, extraParams)
        } else {
            this.createOrder(formData, extraParams);
        }
    }

    updatePayment(formData, orderId, extraParams) {
        formData.set('orderId', orderId);

        this._client.post(
            adyenCheckoutOptions.updatePaymentUrl,
            formData,
            this.afterSetPayment.bind(this, extraParams)
        );
    }

    createOrder(formData, extraParams) {
        if (parseInt(adyenCheckoutOptions.giftcardDiscount) && !adyenCheckoutOptions.payInFullWithGiftcard) {
            // create Adyen order for partial payments
            this._client.post(
                adyenCheckoutOptions.createOrderUrl,
                JSON.stringify({orderAmount: adyenCheckoutOptions.amount, currency: adyenCheckoutOptions.currency}),
                function (response) {
                    const adyenOrder = JSON.parse(response);
                    if (adyenOrder.resultCode === 'Success') {
                        extraParams = Object.assign(extraParams, {
                            order: adyenOrder
                        });
                    }

                    // create shopware order
                    this._client.post(
                        adyenCheckoutOptions.checkoutOrderUrl,
                        formData,
                        this.afterCreateOrder.bind(this, extraParams)
                    );
                }.bind(this)
            )
        } else {
            this._client.post(
                adyenCheckoutOptions.checkoutOrderUrl,
                formData,
                this.afterCreateOrder.bind(this, extraParams)
            );
        }
    }

    afterCreateOrder(extraParams={}, response) {
        let order;
        try {
            order = JSON.parse(response);
        } catch (error) {
            ElementLoadingIndicatorUtil.remove(document.body);
            console.log('Error: invalid response from Shopware API', response);
            return;
        }

        this.orderId = order.id;
        this.finishUrl = new URL(
            location.origin + adyenCheckoutOptions.paymentFinishUrl);
        this.finishUrl.searchParams.set('orderId', order.id);
        this.errorUrl = new URL(
            location.origin + adyenCheckoutOptions.paymentErrorUrl);
        this.errorUrl.searchParams.set('orderId', order.id);
        let params = {
            'orderId': this.orderId,
            'finishUrl': this.finishUrl.toString(),
            'errorUrl': this.errorUrl.toString(),
        };
        // Append any extra parameters passed, e.g. stateData
        for (const property in extraParams) {
            params[property] = extraParams[property];
        }

        this._client.post(
            adyenCheckoutOptions.paymentHandleUrl,
            JSON.stringify(params),
            this.afterPayOrder.bind(this, this.orderId),
        );
    }

    afterSetPayment(extraParams={}, response) {
        try {
            const responseObject = JSON.parse(response);
            if (responseObject.success) {
                this.afterCreateOrder(extraParams,
                    JSON.stringify({id: adyenCheckoutOptions.orderId}));
            }
        } catch (e) {
            ElementLoadingIndicatorUtil.remove(document.body);
            console.log('Error: invalid response from Shopware API', response);
            return;
        }
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
                `${adyenCheckoutOptions.paymentStatusUrl}`,
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
            if (!!paymentResponse.action) {
                this.adyenCheckout
                    .createFromAction(paymentResponse.action)
                    .mount('[data-adyen-payment-action-container]');
                const modalActionTypes = ['threeDS2', 'qrCode']
                if (modalActionTypes.includes(paymentResponse.action.type)) {
                    $('[data-adyen-payment-action-modal]').modal({show: true});
                }
            }
        } catch (e) {
            console.log(e);
        }
    }

    initializeCustomPayButton() {

        const componentConfig = adyenConfiguration.componentsWithPayButton[this.selectedAdyenPaymentMethod];

        this.completePendingPayment(this.selectedAdyenPaymentMethod, componentConfig);

        // get selected payment method object
        let selectedPaymentMethod = this.adyenCheckout.paymentMethodsResponse.paymentMethods
            .filter(item => item.type === this.selectedAdyenPaymentMethod);

        if (selectedPaymentMethod.length < 1) {
            return;
        }
        let selectedPaymentMethodObject = selectedPaymentMethod[0];

        if (!adyenCheckoutOptions.amount) {
            console.error('Failed to fetch Cart/Order total amount.');
            return;
        }

        if (!!componentConfig.prePayRedirect) {
            this.renderPrePaymentButton(componentConfig, selectedPaymentMethodObject);
            return;
        }

        const PAY_BUTTON_CONFIG = Object.assign(componentConfig.extra, selectedPaymentMethodObject, {
            amount: {
                value: adyenCheckoutOptions.amount,
                currency: adyenCheckoutOptions.currency,
            },
            data: {
                personalDetails: shopperDetails,
                billingAddress: activeBillingAddress,
                deliveryAddress: activeShippingAddress
            },
            onClick: (resolve, reject) => {
                if (!componentConfig.onClick(resolve, reject, this)) {
                    return false;
                }
                ElementLoadingIndicatorUtil.create(document.body);
            },
            onSubmit: function (state, component) {
                if (state.isValid) {
                    let extraParams = {
                        stateData: JSON.stringify(state.data)
                    };
                    let formData = FormSerializeUtil.serialize(this.confirmOrderForm);
                    // Set a custom response action handler if available.
                    if ('responseHandler' in componentConfig) {
                        this.responseHandler = componentConfig.responseHandler.bind(component, this);
                    }

                    this.confirmOrder(formData, extraParams);
                } else {
                    component.showValidation();
                    if (this.adyenCheckout.options.environment === 'test') {
                        console.log('Payment failed: ', state);
                    }
                }
            }.bind(this),
            onCancel: (data, component) => {
                ElementLoadingIndicatorUtil.remove(document.body);
                componentConfig.onCancel(data, component, this);
            },
            onError: (error, component) => {
                if (component.props.name === 'PayPal' && error.name === 'CANCEL') {
                    this._client.post(
                        `${adyenCheckoutOptions.cancelOrderTransactionUrl}`,
                        JSON.stringify({orderId: this.orderId})
                    );
                }

                ElementLoadingIndicatorUtil.remove(document.body);
                componentConfig.onError(error, component, this);
                console.log(error);
            }
        });

        const paymentMethodInstance = this.adyenCheckout.create(
            selectedPaymentMethodObject.type,
            PAY_BUTTON_CONFIG
        );

        try {
            if ('isAvailable' in paymentMethodInstance) {
                paymentMethodInstance.isAvailable().then(function () {
                    this.mountCustomPayButton(paymentMethodInstance);
                }.bind(this)).catch(e => {
                    console.log(selectedPaymentMethodObject.type + ' is not available', e);
                });
            } else {
                this.mountCustomPayButton(paymentMethodInstance);
            }
        } catch (e) {
            console.log(e);
        }
    }

    renderPrePaymentButton(componentConfig, selectedPaymentMethodObject) {
        if (selectedPaymentMethodObject.type === 'amazonpay') {
            componentConfig.extra = this.setAddressDetails(componentConfig.extra);
        }
        const PRE_PAY_BUTTON = Object.assign(componentConfig.extra, selectedPaymentMethodObject, {
            configuration: selectedPaymentMethodObject.configuration,
            amount: {
                value: adyenCheckoutOptions.amount,
                currency: adyenCheckoutOptions.currency,
            },
            onClick: (resolve, reject) => {
                if (!componentConfig.onClick(resolve, reject, this)) {
                    return false;
                }
                ElementLoadingIndicatorUtil.create(document.body);
            },
            onError: (error, component) => {
                ElementLoadingIndicatorUtil.remove(document.body);
                componentConfig.onError(error, component, this);
                console.log(error);
            }
        });
        let paymentMethodInstance = this.adyenCheckout.create(
            selectedPaymentMethodObject.type,
            PRE_PAY_BUTTON
        );
        this.mountCustomPayButton(paymentMethodInstance);
    }

    completePendingPayment(paymentMethodType, config) {
        const url = new URL(location.href);
        // Check for pending payment session
        if (url.searchParams.has(config.sessionKey)) {
            ElementLoadingIndicatorUtil.create(document.body);
            const paymentMethodInstance = this.adyenCheckout.create(paymentMethodType, {
                [config.sessionKey]: url.searchParams.get(config.sessionKey),
                showOrderButton: false,
                onSubmit: function (state, component) {
                    if (state.isValid) {
                        let extraParams = {
                            stateData: JSON.stringify(state.data)
                        };
                        let formData = FormSerializeUtil.serialize(this.confirmOrderForm);
                        this.confirmOrder(formData, extraParams);
                    }
                }.bind(this),
            });
            this.mountCustomPayButton(paymentMethodInstance);
            paymentMethodInstance.submit();
        }
    }

    getSelectedPaymentMethodKey() {
        return Object.keys(
            adyenConfiguration.paymentMethodTypeHandlers).find(
                key => adyenConfiguration.paymentMethodTypeHandlers[key] ===
                    adyenCheckoutOptions.selectedPaymentMethodHandler);
    }

    mountCustomPayButton(paymentMethodInstance) {
        let form = document.querySelector('#confirmOrderForm');
        if (form) {
            let submitButton = form.querySelector('button[type=submit]');
            if (submitButton && !submitButton.disabled) {
                let confirmButtonContainer = document.createElement('div');
                confirmButtonContainer.id = 'adyen-confirm-button';
                confirmButtonContainer.setAttribute('data-adyen-confirm-button', '')
                form.appendChild(confirmButtonContainer);
                paymentMethodInstance.mount(confirmButtonContainer);
                submitButton.remove();
            }
        }
    }

    mountPaymentComponent(paymentMethod, isOneClick = false) {
        const configuration = Object.assign({}, paymentMethod, {
            data: {
                personalDetails: shopperDetails,
                billingAddress: activeBillingAddress,
                deliveryAddress: activeShippingAddress
            },
            onSubmit: function(state, component) {
                if (state.isValid) {
                    let extraParams = {
                        stateData: JSON.stringify(state.data)
                    };
                    let formData = FormSerializeUtil.serialize(this.confirmOrderForm);
                    ElementLoadingIndicatorUtil.create(document.body);
                    this.confirmOrder(formData, extraParams);
                } else {
                    component.showValidation();
                    if (this.adyenCheckout.options.environment === 'test') {
                        console.log('Payment failed: ', state);
                    }
                }
            }.bind(this)
        });
        if (!isOneClick && paymentMethod.type === 'scheme' && adyenCheckoutOptions.displaySaveCreditCardOption) {
            configuration.enableStoreDetails = true;
        }
        try {
            const paymentMethodInstance = this.adyenCheckout.create(paymentMethod.type, configuration);
            paymentMethodInstance.mount('#' + this.el.id);
            this.confirmFormSubmit.addEventListener('click', function(event) {
                const form =  DomAccess.querySelector(document, '#confirmOrderForm');
                if (!form.checkValidity()) {
                    return;
                }
                event.preventDefault();
                this.el.parentNode.scrollIntoView({
                    behavior: "smooth",
                    block:    "start",
                });
                paymentMethodInstance.submit();
            }.bind(this));
        } catch (err) {
            console.error(paymentMethod.type, err);
            return false;
        }
    }

    appendGiftcardSummary() {
        if(parseInt(adyenCheckoutOptions.giftcardDiscount) && this.shoppingCartSummaryBlock.length) {
            this.shoppingCartSummaryDetails = $('<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">Giftcard discount</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' + adyenCheckoutOptions.currencySymbol + this.giftcardDiscount + '</dd>' +
                '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">Remaining amount</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' + adyenCheckoutOptions.currencySymbol + this.remainingAmount + '</dd>');
            this.shoppingCartSummaryDetails.appendTo(this.shoppingCartSummaryBlock[0]);
        }
    }

    /**
     * Set the address details based on the passed data in confirm-payment twig file
     * If no phone number is linked to customer, do not set addressDetails and update Product Type
     *
     * @param extra
     */
    setAddressDetails(extra) {
        if (activeShippingAddress.phoneNumber !== '') {
            extra.addressDetails = {
                name: shopperDetails.firstName + ' ' + shopperDetails.lastName,
                addressLine1: activeShippingAddress.street,
                city: activeShippingAddress.city,
                postalCode: activeShippingAddress.postalCode,
                countryCode: activeShippingAddress.country,
                phoneNumber: activeShippingAddress.phoneNumber
            };
        } else {
            extra.productType = 'PayOnly';
        }

        return extra;
    }
}
