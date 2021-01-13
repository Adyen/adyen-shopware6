import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import StoreApiClient from 'src/service/store-api-client.service';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import ElementLoadingIndicatorUtil
    from 'src/utility/loading-indicator/element-loading-indicator.util';
import adyenConfiguration from '../configuration/adyen';

/* global adyenCheckoutOptions */
/* eslint-disable no-unused-vars */
export default class ConfirmOrderPlugin extends Plugin {

    init() {
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
            let selectedAdyenPaymentMethodHandler = adyenCheckoutOptions.selectedPaymentMethodHandler;

            let selectedAdyenPaymentMethod = Object.keys(
                adyenConfiguration.paymentMethodTypeHandlers).
                find(
                    key => adyenConfiguration.paymentMethodTypeHandlers[key] ===
                        selectedAdyenPaymentMethodHandler);

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
        if (!!order.data) {
            order = order.data;
        }
        window.orderId = order.id;
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
            window.adyenCheckout.createFromAction(paymentActionResponse.action).
                mount('[data-adyen-payment-action-container]');
        } catch (e) {
            console.log(e);
        }

    }
}
