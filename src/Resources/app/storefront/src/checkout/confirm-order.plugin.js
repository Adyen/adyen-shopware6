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
            this.validateAndConfirmOrder.bind(this));
        this.initializeCustomPayButton();
    }

    validateAndConfirmOrder(event) {
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
            ElementLoadingIndicatorUtil.create(document.body);

            const formData = FormSerializeUtil.serialize(form);

            this.confirmOrder(formData);
        }
    }

    confirmOrder(formData, extraParams= {}) {
        const orderId = adyenCheckoutOptions.orderId;
        let url = null;
        let callback = null;
        if (!!orderId) { //Only used if the order is being edited
            formData.set('orderId', orderId);
            url = adyenCheckoutOptions.editPaymentUrl;
            callback = this.afterSetPayment.bind(this, extraParams);
        } else {
            url = adyenCheckoutOptions.checkoutOrderUrl;
            callback = this.afterCreateOrder.bind(this, extraParams);
        }
        this._client.post(url, formData, callback);
    }

    afterCreateOrder(extraParams= {}, response) {
        let order;
        try {
            order = JSON.parse(response);
        } catch (error) {
            // Response is not a valid JSON
            // TODO error handling
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
        for (const property in extraParams) {
            params[property] = JSON.stringify(extraParams[property]);
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
            console.log(e);
        }
    }

    afterPayOrder(orderId, response) {
        try {
            response = JSON.parse(response);
            this.returnUrl = response.redirectUrl;
        } catch (e) {
            console.log(e);
            return;
        }

        // If payment call returns the errorUrl, then no need to proceed further.
        // Redirect to error page.
        if (this.returnUrl == this.errorUrl.toString()) {
            location.href = this.returnUrl;
        }

        try {
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
                location.href = this.returnUrl;
            }
            if (!!paymentActionResponse.action) {
                window.adyenCheckout
                    .createFromAction(paymentActionResponse.action)
                    .mount('[data-adyen-payment-action-container]');
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

        const ADYEN_CHECKOUT_CONFIG = Object.assign(
            {},
            adyenCheckoutConfiguration,
            {paymentMethodsResponse: JSON.parse(adyenCheckoutConfiguration.paymentMethodsResponse)}
        );
        let adyenCheckout = new AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
        // get selected payment method object
        let selectedPaymentMethod = adyenCheckout.paymentMethodsResponse.paymentMethods
            .filter(item => item.type == selectedAdyenPaymentMethod);

        if (selectedPaymentMethod.length < 1) {
            return;
        }
        let selectedPaymentMethodObject = selectedPaymentMethod[0];

        if (!!!adyenCheckoutOptions.amount) {
            console.error('Failed to fetch Cart/Order total amount.');
            return;
        }

        const PAY_BUTTON_CONFIG = Object.assign({}, selectedPaymentMethodObject, {
            showPayButton: true,
            amount: {
                value: adyenCheckoutOptions.amount,
                currency: adyenCheckoutOptions.currency,
            },
            onClick: (resolve, reject) => {
                if (!this.confirmOrderForm.checkValidity()) {
                    reject();
                } else {
                    ElementLoadingIndicatorUtil.create(document.body);
                    resolve();
                }
            },
            onSubmit: function (state, component) {
                if (state.isValid) {
                    let extraParams = {
                        stateData: state.data
                    };
                    let formData = FormSerializeUtil.serialize(this.confirmOrderForm);
                    this.confirmOrder(formData, extraParams);
                } else {
                    component.showValidation();
                    console.log('Payment failed: ', state);
                }
            }.bind(this),
            onError: function (error) {
                console.log('Error: ', error);
            }
        });

        let paymentMethodInstance;

        paymentMethodInstance = adyenCheckout.create(
            selectedPaymentMethodObject.type,
            PAY_BUTTON_CONFIG
        );

        try {
            if ('isAvailable' in paymentMethodInstance) {
                paymentMethodInstance.isAvailable().then(() => {
                    $('#confirmOrderForm button[type=submit]').remove();
                    let confirmButtonContainer = $('<div id="adyen-confirm-button" data-adyen-confirm-button></div>');
                    $('#confirmOrderForm').append(confirmButtonContainer);
                    paymentMethodInstance.mount(confirmButtonContainer.get(0));
                }).catch(e => {
                    console.log(selectedPaymentMethodObject.type + ' is not available', e);
                });
            }
        } catch (e) {
            console.log(e);
        }
    }

    getSelectedPaymentMethodKey() {
        return Object.keys(
            adyenConfiguration.paymentMethodTypeHandlers).find(
                key => adyenConfiguration.paymentMethodTypeHandlers[key] ===
                    adyenCheckoutOptions.selectedPaymentMethodHandler);
    }
}
