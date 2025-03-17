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
import HttpClient from 'src/service/http-client.service';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';
import adyenConfiguration from '../configuration/adyen';

/* global adyenCheckoutOptions, adyenCheckoutConfiguration, AdyenCheckout */
/* eslint-disable no-unused-vars */
export default class ConfirmOrderPlugin extends Plugin {

    init() {
        this._client = new HttpClient();
        this.selectedAdyenPaymentMethod = this.getSelectedPaymentMethodKey();
        this.confirmOrderForm = DomAccess.querySelector(document, '#confirmOrderForm');
        this.checkoutMainContent = DomAccess.querySelector(document, '#content-main');
        this.shoppingCartSummaryBlock = DomAccess.querySelectorAll(document, '.checkout-aside-summary-list');

        this.minorUnitsQuotient = adyenCheckoutOptions.amount / adyenCheckoutOptions.totalPrice;
        this.giftcardDiscount = adyenCheckoutOptions.giftcardDiscount;
        this.remainingAmount = adyenCheckoutOptions.totalPrice - this.giftcardDiscount;
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

            if (this.selectedAdyenPaymentMethod === "klarna_b2b") {
                this.checkoutMainContent.addEventListener('click', this.onConfirmOrderSubmit.bind(this));
                return;
            }

            if (adyenConfiguration.updatablePaymentMethods.includes(this.selectedAdyenPaymentMethod) && !this.stateData) {
                // create inline component for cards etc. and set event listener for submit button to confirm payment component
                this.renderPaymentComponent(this.selectedAdyenPaymentMethod);
            } else {
                this.checkoutMainContent.addEventListener('click', this.onConfirmOrderSubmit.bind(this));
            }
        }.bind(this));
        if (adyenCheckoutOptions.payInFullWithGiftcard > 0) {
            if (parseInt(adyenCheckoutOptions.giftcardDiscount, 10)) {
                this.appendGiftcardSummary();
            }
        } else {
            this.appendGiftcardSummary();
        }
    }

    async initializeCheckoutComponent() {
        const {locale, clientKey, environment, merchantAccount} = adyenCheckoutConfiguration;
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
                    holderNameRequired: true,
                    clickToPayConfiguration: {
                        merchantDisplayName: merchantAccount,
                        shopperEmail: shopperDetails.shopperEmail
                    }
                }
            },
        };

        this.adyenCheckout = await AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
    }

    handleOnAdditionalDetails(state) {
        this._client.post(
            `${adyenCheckoutOptions.paymentDetailsUrl}`,
            JSON.stringify({orderId: this.orderId, stateData: JSON.stringify(state.data)}),
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
        const confirmFormSubmit = DomAccess.querySelector(document, '#confirmOrderForm button[type="submit"]');
        if (event.target !== confirmFormSubmit) {
            return;
        }

        const form = DomAccess.querySelector(document, '#confirmOrderForm');
        if (!form.checkValidity()) {
            return;
        }

        if (this.selectedAdyenPaymentMethod === "klarna_b2b") {
            const companyNameElement = DomAccess.querySelector(document, '#adyen-company-name');

            const companyName = companyNameElement ? companyNameElement.value.trim() : '';
            const companyNameError = DomAccess.querySelector(document, '#adyen-company-name-error');
            companyNameError.style.display = 'none';

            let hasError = false;

            if (!companyName) {
                companyNameError.style.display = 'block';
                hasError = true;
            }

            if (hasError) {
                event.preventDefault();
                return;
            }
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
        if (type === 'giftcard') {
            return;
        }

        // Get the payment method object from paymentMethodsResponse
        let paymentMethodConfigs = this.adyenCheckout.paymentMethodsResponse.paymentMethods.filter(function (paymentMethod) {
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
            this.mountPaymentComponent(paymentMethod, true, selector);
        });

        this.hideStorePaymentMethodComponents();
        let selectedId = null;
        let storedPaymentMethodFields = DomAccess.querySelectorAll(document, '[name=adyenStoredPaymentMethodId]');
        storedPaymentMethodFields.forEach(field => {
            if (!selectedId) {
                selectedId = field.value;
            }
            field.addEventListener('change', this.showSelectedStoredPaymentMethod.bind(this));
        });
        this.showSelectedStoredPaymentMethod(null, selectedId);
    }

    showSelectedStoredPaymentMethod(event, selectedId = null) {
        // Only show the component for the selected stored payment method
        this.hideStorePaymentMethodComponents();
        selectedId = event ? event.target.value : selectedId;
        let selector = `[data-adyen-stored-payment-method-id="${selectedId}"]`;
        let component = DomAccess.querySelector(document, selector);
        component.style.display = 'block';
    }

    hideStorePaymentMethodComponents() {
        let storedPaymentComponents = DomAccess.querySelectorAll(document, '.stored-payment-component');
        storedPaymentComponents.forEach(component => {
            component.style.display = 'none';
        });
    }

    confirmOrder(formData, extraParams = {}) {
        const orderId = adyenCheckoutOptions.orderId;
        formData.set('affiliateCode', adyenCheckoutOptions.affiliateCode);
        formData.set('campaignCode', adyenCheckoutOptions.campaignCode);
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
        this._client.post(
            adyenCheckoutOptions.checkoutOrderUrl,
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

        if (order.url) {
            location.href = order.url;

            return;
        }

        this.orderId = order.id;
        this.finishUrl = new URL(
            location.origin + adyenCheckoutOptions.paymentFinishUrl);
        this.finishUrl.searchParams.set('orderId', order.id);
        this.errorUrl = new URL(
            location.origin + adyenCheckoutOptions.paymentErrorUrl);
        this.errorUrl.searchParams.set('orderId', order.id);

        if (adyenCheckoutOptions.selectedPaymentMethodHandler === 'handler_adyen_billiepaymentmethodhandler') {
            const companyNameElement = DomAccess.querySelector(document, '#adyen-company-name');
            const companyName = companyNameElement ? companyNameElement.value : '';
            const registrationNumberElement = DomAccess.querySelector(document, '#adyen-registration-number');
            const registrationNumber = registrationNumberElement ? registrationNumberElement.value : '';

            extraParams.companyName = companyName;
            extraParams.registrationNumber = registrationNumber;
        }

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

    afterSetPayment(extraParams = {}, response) {
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
                const actionModalConfiguration = {};
                if (paymentResponse.action.type === 'threeDS2') {
                    actionModalConfiguration.challengeWindowSize = '05';
                }

                this.adyenCheckout
                    .createFromAction(paymentResponse.action, actionModalConfiguration)
                    .mount('[data-adyen-payment-action-container]');
                const modalActionTypes = ['threeDS2', 'qrCode']
                if (modalActionTypes.includes(paymentResponse.action.type)) {
                    const bootstrapVersion = window.jQuery && $.fn.tooltip && $.fn.tooltip.Constructor && $.fn.tooltip.Constructor.VERSION;
                    const isBootstrap4 = bootstrapVersion && bootstrapVersion.startsWith('4');
                    if (window.jQuery && isBootstrap4) {
                        // Bootstrap v4 support
                        $('[data-adyen-payment-action-modal]').modal({show: true});
                    } else {
                        // Bootstrap v5 support
                        var adyenPaymentModal = new bootstrap.Modal(document.getElementById('adyen-payment-action-modal'), {
                            keyboard: false
                        });
                        adyenPaymentModal.show();
                    }
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

        /*
         * If the PM is GooglePay in Shopware, check for the `paywithgoogle` tx_variant also in paymentMethods response.
         * This block should be remove after depreating the `paywithgoogle` tx_variant.
         */
        // TODO: Following block will be removed after the deprecation of the `paywithgoogle` tx_variant.
        if (selectedPaymentMethod.length < 1 && this.selectedAdyenPaymentMethod === 'googlepay') {
            selectedPaymentMethod = this.adyenCheckout.paymentMethodsResponse.paymentMethods
                .filter(item => item.type === 'paywithgoogle');
        }

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
                if (component.props.name === 'PayPal') {
                    this._client.post(
                        `${adyenCheckoutOptions.cancelOrderTransactionUrl}`,
                        JSON.stringify({orderId: this.orderId}),
                        () => {
                            ElementLoadingIndicatorUtil.remove(document.body);
                            componentConfig.onError(error, component, this);
                        }
                    );

                    return;
                }

                ElementLoadingIndicatorUtil.remove(document.body);
                componentConfig.onError(error, component, this);
                console.log(error);
            }
        });

        if ((selectedPaymentMethodObject.type === "paywithgoogle" || selectedPaymentMethodObject.type === "googlepay")
            && (adyenCheckoutOptions.googleMerchantId !== "" && adyenCheckoutOptions.gatewayMerchantId !== "")) {
            PAY_BUTTON_CONFIG.configuration = {
                merchantId: adyenCheckoutOptions.googleMerchantId,
                gatewayMerchantId: adyenCheckoutOptions.gatewayMerchantId
            };
        }

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

    mountPaymentComponent(paymentMethod, isOneClick = false, selector = null) {
        const configuration = Object.assign({}, paymentMethod, {
            data: {
                personalDetails: shopperDetails,
                billingAddress: activeBillingAddress,
                deliveryAddress: activeShippingAddress
            },
            onSubmit: function (state, component) {
                if (state.isValid) {
                    if (isOneClick) {
                        state.data.paymentMethod.holderName = paymentMethod.holderName ?? '';
                    }

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
        let componentSelector = isOneClick ? selector : '#' + this.el.id;
        try {
            const paymentMethodInstance = this.adyenCheckout.create(paymentMethod.type, configuration);
            paymentMethodInstance.mount(componentSelector);
            this.checkoutMainContent.addEventListener('click', function (event) {
                const confirmFormSubmit = DomAccess.querySelector(document, '#confirmOrderForm button[type="submit"]');
                if (event.target !== confirmFormSubmit) {
                    return;
                }

                const form = DomAccess.querySelector(document, '#confirmOrderForm');
                if (!form.checkValidity()) {
                    return;
                }
                event.preventDefault();
                this.el.parentNode.scrollIntoView({
                    behavior: "smooth",
                    block: "start",
                });
                paymentMethodInstance.submit();
            }.bind(this));
        } catch (err) {
            console.error(paymentMethod.type, err);
            return false;
        }
    }

    appendGiftcardSummary() {
        if (parseInt(adyenCheckoutOptions.giftcardDiscount, 10) && this.shoppingCartSummaryBlock.length) {
            let giftcardDiscount = parseFloat(this.giftcardDiscount).toFixed(2);
            let remainingAmount = parseFloat(this.remainingAmount).toFixed(2);

            let shoppingCartSummaryDetails =
                '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">' +
                adyenCheckoutOptions.translationAdyenGiftcardDiscount +
                '</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' +
                adyenCheckoutOptions.currencySymbol + giftcardDiscount +
                '</dd>' +
                '<dt class="col-7 checkout-aside-summary-label checkout-aside-summary-total adyen-giftcard-summary">' +
                adyenCheckoutOptions.translationAdyenGiftcardRemainingAmount +
                '</dt>' +
                '<dd class="col-5 checkout-aside-summary-value checkout-aside-summary-total adyen-giftcard-summary">' +
                adyenCheckoutOptions.currencySymbol + remainingAmount +
                '</dd>';

            this.shoppingCartSummaryBlock[0].innerHTML += shoppingCartSummaryDetails;
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
