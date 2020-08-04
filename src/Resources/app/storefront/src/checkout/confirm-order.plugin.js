import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import StoreApiClient from 'src/service/store-api-client.service';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

/* global adyenCheckoutOptions */
/* eslint-disable no-unused-vars */
export default class ConfirmOrderPlugin extends Plugin {

    init() {
        const confirmOrderForm = DomAccess.querySelector(document, '#confirmOrderForm');
        confirmOrderForm.addEventListener('submit', this.confirmOrder.bind(this));
    }

    confirmOrder(event) {
        if (!!adyenCheckoutOptions && !!adyenCheckoutOptions.paymentStatusUrl && adyenCheckoutOptions.checkoutOrderUrl) {
        event.preventDefault();
        const form = event.target;
        if (!form.checkValidity()) {
            return;
        }

        this._client = new StoreApiClient();
        const formData = FormSerializeUtil.serialize(form);

        ElementLoadingIndicatorUtil.create(document.body);

        const orderId = this.options.orderId;
        const request = new XMLHttpRequest();
        let callback = null;
        if (!!orderId) { //Only used if the order is being edited
            formData.set('orderId', orderId);
            request.open('POST', ''); //TODO define URL for order edit flow
            callback = this.afterSetPayment.bind(this);
        } else {
            request.open('POST', adyenCheckoutOptions.checkoutOrderUrl);
            callback = this.afterCreateOrder.bind(this);
        }
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('sw-language-id', adyenCheckoutOptions.languageId);
        this._client._sendRequest(request, formData, callback);
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
        window.orderId = order.data.id;
        const finishUrl = new URL(location.origin + adyenCheckoutOptions.paymentFinishUrl);
        finishUrl.searchParams.set('orderId', order.data.id);
        const errorUrl = new URL(location.origin + adyenCheckoutOptions.paymentErrorUrl);
        errorUrl.searchParams.set('orderId', order.data.id);
        const params = {
            'finishUrl': finishUrl.toString(),
            'errorUrl': errorUrl.toString()
        };

        this._client.post(
            `${adyenCheckoutOptions.checkoutOrderUrl}/${window.orderId}/pay`,
            JSON.stringify(params),
            this.afterPayOrder.bind(this, window.orderId)
        );
    }

    afterPayOrder(orderId, response) {
        response = JSON.parse(response);
        window.returnUrl = response.paymentUrl;

        this._client.post(
            `${adyenCheckoutOptions.paymentStatusUrl}`,
            JSON.stringify({'orderId': orderId}),
            this.handlePaymentAction.bind(this)
        );
    }

    handlePaymentAction(paymentAction) {
        const paymentActionResponse = JSON.parse(paymentAction);

        if (paymentActionResponse.isFinal === true) {
            console.log(paymentActionResponse, window.returnUrl);
            location.href = window.returnUrl;
            return;
        }

        try{
            window.adyenCheckout
                .createFromAction(paymentActionResponse.action)
                .mount('[data-adyen-payment-action-container]');
        }
        catch (e) {
            console.log('error');
        }

    }
}
