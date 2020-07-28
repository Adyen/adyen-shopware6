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
        if (orderId !== null) {
            formData.set('orderId', orderId);
            request.open('POST', '/sales-channel-api/v1/adyen/payment');
            callback = this.afterSetPayment.bind(this);
        } else {
            request.open('POST', this.options.checkoutOrderUrl);
            callback = this.afterCreateOrder.bind(this);
        }
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('sw-language-id', adyenCheckoutOptions.languageId);
        this._client._sendRequest(request, formData, callback);
    }

    afterCreateOrder(response) {

    }

    afterSetPayment(response) {

    }

    afterPayOrder(response) {

    }

}
