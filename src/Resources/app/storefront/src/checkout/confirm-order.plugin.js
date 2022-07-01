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
        this.confirmOrderForm = DomAccess.querySelector(document,
            '#confirmOrderForm');
        this.confirmOrderForm.addEventListener('submit',
            this.onConfirmOrderSubmit.bind(this));
        this.paymentComponent = $(`[data-adyen-payment-component]`);
        this.responseHandler = this.handlePaymentAction;
        this.adyenCheckout = Promise;
        this.initializeCheckoutComponent().then(function () {
            this.initializeCustomPayButton();
        }.bind(this));
    }

    async initializeCheckoutComponent () {
        const { locale, clientKey, environment, paymentMethodsResponse } = adyenCheckoutConfiguration;
        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            clientKey,
            environment,
            showPayButton: true,
            hasHolderName: true,
            paymentMethodsResponse: JSON.parse(paymentMethodsResponse),
            onAdditionalDetails: this.handleOnAdditionalDetails.bind(this)
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
        // Non adyen payment method selected
        if (adyenCheckoutOptions.selectedPaymentMethodPluginId !==
            adyenCheckoutOptions.adyenPluginId) {
            return true;
        }

        if (!adyenCheckoutOptions || !adyenCheckoutOptions.paymentStatusUrl ||
            !adyenCheckoutOptions.checkoutOrderUrl || !adyenCheckoutOptions.paymentHandleUrl) {
            console.error('Adyen payment configuration missing.');
            return;
        }

        const form = event.target;
        if (!form.checkValidity()) {
            return;
        }

        event.preventDefault();

        ElementLoadingIndicatorUtil.create(document.body);

        // get selected payment method
        let selectedAdyenPaymentMethod = this.getSelectedPaymentMethodKey();

        const updatableSelected = adyenConfiguration.updatablePaymentMethods.includes(selectedAdyenPaymentMethod);

        if (updatableSelected && !this.stateData) {
            // render component to collect payment data
            this.renderPaymentComponent(selectedAdyenPaymentMethod);
            $('[data-adyen-payment-component-modal]').modal({show: true}).on('hidden.bs.modal', function (e) {
                window.location.reload();
            });
            return;
        }

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
        this.mountPaymentComponent(paymentMethod, '[data-adyen-payment-container]', false);
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
        let url = null;
        let callback = null;
        if (!!orderId) { //Only used if the order is being edited
            formData.set('orderId', orderId);
            url = adyenCheckoutOptions.updatePaymentUrl;
            callback = this.afterSetPayment.bind(this, extraParams);
        } else {
            url = adyenCheckoutOptions.checkoutOrderUrl;
            callback = this.afterCreateOrder.bind(this, extraParams);
        }
        this._client.post(url, formData, callback);
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
        $('[data-adyen-payment-component-modal]').modal().hide();
        try {
            const paymentResponse = JSON.parse(response);
            if (paymentResponse.isFinal) {
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
        // get selected payment method
        let selectedAdyenPaymentMethod = this.getSelectedPaymentMethodKey();
        if (!(selectedAdyenPaymentMethod in adyenConfiguration.componentsWithPayButton)) {
            return;
        }

        const componentConfig = adyenConfiguration.componentsWithPayButton[selectedAdyenPaymentMethod];

        this.completePendingPayment(selectedAdyenPaymentMethod, componentConfig);

        // get selected payment method object
        let selectedPaymentMethod = this.adyenCheckout.paymentMethodsResponse.paymentMethods
            .filter(item => item.type === selectedAdyenPaymentMethod);

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
        let confirmButtonContainer = $('<div id="adyen-confirm-button" data-adyen-confirm-button></div>');
        $('#confirmOrderForm').append(confirmButtonContainer);
        paymentMethodInstance.mount(confirmButtonContainer.get(0));
        $('#confirmOrderForm button[type=submit]').remove();
    }

    mountPaymentComponent(paymentMethod, selector, isOneClick = false) {
        const configuration = Object.assign({}, paymentMethod, {
            data: {
                personalDetails: shopperDetails,
                billingAddress: activeBillingAddress,
                deliveryAddress: activeShippingAddress
            },
            onSubmit: function(state, component) {
                this.paymentComponent.find('.loader').show();
                this.paymentComponent.find('[data-adyen-payment-container]').hide();
                if (state.isValid) {
                    let extraParams = {
                        stateData: JSON.stringify(state.data)
                    };
                    let formData = FormSerializeUtil.serialize(this.confirmOrderForm);
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
            paymentMethodInstance.mount(this.paymentComponent.find(selector).get(0));
            this.paymentComponent.find('.loader').hide();
        } catch (err) {
            console.error(paymentMethod.type, err);
            return false;
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
