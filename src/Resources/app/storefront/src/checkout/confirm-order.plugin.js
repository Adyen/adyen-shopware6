import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import StoreApiClient from 'src/service/store-api-client.service';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';
import adyenConfiguration from '../configuration/adyen';
//import CheckoutPlugin from './checkout.plugin';

/* global adyenCheckoutOptions, adyenCheckoutConfiguration, AdyenCheckout */
/* eslint-disable no-unused-vars */
export default class ConfirmOrderPlugin extends Plugin {

    init() {
        this.initializeCustomPayButton();
        const confirmOrderForm = DomAccess.querySelector(document,
            '#confirmOrderForm');
        confirmOrderForm.addEventListener('submit',
            this.confirmOrder.bind(this));
    }

    confirmOrder(event) {
        // Non adyen payment method selected
        if (adyenCheckoutOptions.selectedPaymentMethodPluginId !==
            adyenCheckoutOptions.adyenPluginId) {
            return true;
        }

        if (!!adyenCheckoutOptions && !!adyenCheckoutOptions.paymentStatusUrl &&
            adyenCheckoutOptions.checkoutOrderUrl && adyenCheckoutOptions.paymentHandleUrl) {
            event.preventDefault();

            // get selected payment method
            let selectedAdyenPaymentMethod = this.getSelectedPaymentMethodKey();

            const confirmPaymentModal = $('#confirmPaymentModal');
            // Do validation if the payment method has all the required data submitted by the customer
            // 1. case: State data is not stored
            if (!adyenCheckoutOptions.statedataPaymentMethod) {
                if (adyenConfiguration.updatablePaymentMethods.includes(
                    selectedAdyenPaymentMethod)) {
                    // Show popup to customer to fill in the missing payment details
                    confirmPaymentModal.modal('show');
                    return;
                }
            } else {
                // 2. case: StateData is stored
                if (!adyenConfiguration.updatablePaymentMethods.includes(
                    selectedAdyenPaymentMethod)) {
                    // State data should not be stored for any payment method which does not require it but if it happens show popup to the customer to save the payment method again
                    confirmPaymentModal.modal('show');
                    return;
                }
            }

            const form = event.target;
            if (!form.checkValidity()) {
                return;
            }

            this._client = new StoreApiClient();
            const formData = FormSerializeUtil.serialize(form);

            ElementLoadingIndicatorUtil.create(document.body);

            const orderId = adyenCheckoutOptions.orderId;
            let url = null;
            let callback = null;
            if (!!orderId) { //Only used if the order is being edited
                formData.set('orderId', orderId);
                url = adyenCheckoutOptions.editPaymentUrl;
                callback = this.afterSetPayment.bind(this);
            } else {
                url = adyenCheckoutOptions.checkoutOrderUrl;
                callback = this.afterCreateOrder.bind(this);
            }
            this._client.post(url, formData, callback);
        }
    }

    afterCreateOrder(response) {
        let order;

        try {
            order = JSON.parse(response);
        } catch (error) {
            // Response is not a valid JSON
            // TODO error handling
            return;
        }
        window.orderId = !!order.data ? order.data.id : order.id;
        const finishUrl = new URL(
            location.origin + adyenCheckoutOptions.paymentFinishUrl);
        finishUrl.searchParams.set('orderId', order.id);
        const errorUrl = new URL(
            location.origin + adyenCheckoutOptions.paymentErrorUrl);
        errorUrl.searchParams.set('orderId', order.id);
        const params = {
            'orderId': window.orderId,
            'finishUrl': finishUrl.toString(),
            'errorUrl': errorUrl.toString(),
        };

        this._client.post(
            adyenCheckoutOptions.paymentHandleUrl,
            JSON.stringify(params),
            this.afterPayOrder.bind(this, window.orderId),
        );
    }

    afterSetPayment(response) {
        try {
            const responseObject = JSON.parse(response);
            if (responseObject.success) {
                this.afterCreateOrder(
                    JSON.stringify({id: adyenCheckoutOptions.orderId}));
            }
        } catch (e) {
            console.log(e);
        }
    }

    afterPayOrder(orderId, response) {
        try {
            response = JSON.parse(response);
            window.returnUrl = response.redirectUrl;

            this._client.post(
                `${adyenCheckoutOptions.paymentStatusUrl}`,
                JSON.stringify({'orderId': orderId}),
                this.handlePaymentAction.bind(this),
            );
        } catch (e) {
            console.log(e);
        }
    }

    handlePaymentAction(paymentAction) {
        try {
            const paymentActionResponse = JSON.parse(paymentAction);
            if (paymentActionResponse.isFinal) {
                location.href = window.returnUrl;
            }
            if (!!paymentActionResponse.action) {
                window.adyenCheckout.createFromAction(paymentActionResponse.action).
                mount('[data-adyen-payment-action-container]');
            }
        } catch (e) {
            console.log(e);
        }

    }

    initializeCustomPayButton() {
        // get selected payment method
        let selectedAdyenPaymentMethod = this.getSelectedPaymentMethodKey();
        if (!adyenConfiguration.componentsWithPayButton.includes(selectedAdyenPaymentMethod)) {
            return;
        }

        let config = Object.assign({}, adyenCheckoutConfiguration);
        config.paymentMethodsResponse = JSON.parse(config.paymentMethodsResponse);
        let adyenCheckout = new AdyenCheckout(config);

        // get selected payment method object
        let selectedPaymentMethodObject = adyenCheckout.paymentMethodsResponse.paymentMethods.filter(item => item.type == selectedAdyenPaymentMethod)[0];

        const paymentMethodInstance = adyenCheckout.create(
            selectedPaymentMethodObject.type,
            Object.assign(selectedPaymentMethodObject, {
                showPayButton: true,
                countryCode: 'NL',
                currency: 'EUR',
                amount: { // Use this after above removed
                    value: 600, //TODO replace with real values
                    currency: 'EUR',
                },
                totalPriceLabel: 'merchantDisplayName',
                onClick: function (resolve, reject) {
                    resolve();
                }
            })
        );

        try {
            if ('isAvailable' in paymentMethodInstance) {
                paymentMethodInstance.isAvailable().then(() => {
                    $('#confirmFormSubmit').remove();
                    let confirmButtonContainer = $('<div id="adyen-confirm-button" data-adyen-confirm-button></div>');
                    $('#confirmOrderForm').append(confirmButtonContainer);
                    paymentMethodInstance.mount(confirmButtonContainer.get(0));
                }).catch(e => {
                    console.log(selectedPaymentMethodObject.type +
                        ' is not available, the method will be hidden from the payment list', e);
                });
            }
        } catch (e) {
            console.log(e);
        }
        console.log(selectedPaymentMethodObject.type, ' button initialized.');
    }

    getSelectedPaymentMethodKey() {
        return Object.keys(
            adyenConfiguration.paymentMethodTypeHandlers).find(
                key => adyenConfiguration.paymentMethodTypeHandlers[key] ===
                    adyenCheckoutOptions.selectedPaymentMethodHandler);
    }
}
