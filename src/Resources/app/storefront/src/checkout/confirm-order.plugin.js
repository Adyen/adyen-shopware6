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
        this.initializeCustomPayButton();
        const confirmOrderForm = DomAccess.querySelector(document,
            '#confirmOrderForm');
        confirmOrderForm.addEventListener('submit',
            this.validateAndConfirmOrder.bind(this));
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

    confirmOrder(formData) {
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

    afterCreateOrder(response) {
        let order;

        try {
            order = JSON.parse(response);
        } catch (error) {
            // Response is not a valid JSON
            // TODO error handling
            return;
        }
        window.orderId = order.id;
        window.finishUrl = new URL(
            location.origin + adyenCheckoutOptions.paymentFinishUrl);
        window.finishUrl.searchParams.set('orderId', order.id);
        window.errorUrl = new URL(
            location.origin + adyenCheckoutOptions.paymentErrorUrl);
        window.errorUrl.searchParams.set('orderId', order.id);
        const params = {
            'orderId': window.orderId,
            'finishUrl': window.finishUrl.toString(),
            'errorUrl': window.errorUrl.toString(),
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
        } catch (e) {
            console.log(e);
            return;
        }

        if (window.returnUrl == window.errorUrl.toString()) {
            location.href = window.returnUrl;
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
                location.href = window.returnUrl;
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

        const createPayButton = function (response) {
            let cart = JSON.parse(response);

            const PAY_BUTTON_CONFIG = Object.assign({}, selectedPaymentMethodObject, {
                showPayButton: true,
                amount: {
                    value: cart.price.totalPrice * 100,
                    currency: adyenCheckoutOptions.currency,
                },
                onClick: (resolve, reject) => {
                    let tos = $('input#tos');
                    if (tos.is(':checked')) {
                        ElementLoadingIndicatorUtil.create(document.body);
                        resolve();
                    } else {
                        tos.focus();
                        reject();
                    }
                },
                onSubmit: function (state) {
                    if (state.isValid) {
                        let params = {
                            adyenStateData: state.data,
                            adyenOrigin: window.location.origin,
                        };
                        this._client.post(adyenCheckoutOptions.stateDataUrl, JSON.stringify(params), (response) => {
                            let formData = new FormData();
                            if (adyenCheckoutOptions.orderId) {
                                const paymentMethodId = $(`[data-adyen-payment-method="${adyenCheckoutOptions.selectedPaymentMethodHandler}"]`)
                                    .attr('data-adyen-payment-method-id');
                                formData.set('paymentMethodId', paymentMethodId);
                            }
                            this.confirmOrder(formData);
                        });
                    } else {
                        console.log('Payment failed: ', state);
                    }
                }.bind(this),
                onError: (error) => {
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

        try {
            this._client.post(adyenCheckoutOptions.cartUrl, new FormData(), createPayButton.bind(this));
        } catch (e) {
            console.log('Error: ', e);
            return;
        }

    }

    getSelectedPaymentMethodKey() {
        return Object.keys(
            adyenConfiguration.paymentMethodTypeHandlers).find(
                key => adyenConfiguration.paymentMethodTypeHandlers[key] ===
                    adyenCheckoutOptions.selectedPaymentMethodHandler);
    }
}
