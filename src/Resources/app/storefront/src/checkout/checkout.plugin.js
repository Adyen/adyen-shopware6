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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

import DomAccess from 'src/helper/dom-access.helper';
import Plugin from 'src/plugin-system/plugin.class';
import StoreApiClient from 'src/service/store-api-client.service';
import validatePaymentMethod from '../validator/paymentMethod';
import FormValidatorWithComponent from '../validator/FormValidatorWithComponent';


/* global adyenCheckoutConfiguration, AdyenCheckout, adyenCheckoutOptions */
/* eslint-disable no-unused-vars */
export default class CheckoutPlugin extends Plugin {

    init() {
        const confirmPaymentForm = DomAccess.querySelector(document, '#confirmPaymentForm');
        confirmPaymentForm.addEventListener('submit', this.onConfirmPayment.bind(this));

        const cardsFormattedHandlerIdentifier = 'handler_adyen_cardspaymentmethodhandler';
        const idealFormattedHandlerIdentifier = 'handler_adyen_idealpaymentmethodhandler';
        const klarnaAccountFormattedHandlerIdentifier = 'handler_adyen_klarnaaccountpaymentmethodhandler';
        const klarnaPayNowFormattedHandlerIdentifier = 'handler_adyen_klarnapaynowpaymentmethodhandler';
        const klarnaPayLaterFormattedHandlerIdentifier = 'handler_adyen_klarnapaylaterpaynowpaymentmethodhandler';
        const sepaFormattedHandlerIdentifier = 'handler_adyen_sepapaymentmethodhandler';
        const sofortFormattedHandlerIdentifier = 'handler_adyen_sofortpaymentmethodhandler';
        const paypalFormattedHandlerIdentifier = 'handler_adyen_paypalpaymentmethodhandler';
        const oneClickFormattedHandlerIdentifier = 'handler_adyen_oneclickpaymentmethodhandler';

        this.paymentMethodTypeHandlers = {
            'scheme': cardsFormattedHandlerIdentifier,
            'ideal': idealFormattedHandlerIdentifier,
            'klarna': klarnaPayLaterFormattedHandlerIdentifier,
            'klarna_account': klarnaAccountFormattedHandlerIdentifier,
            'klarna_paynow': klarnaPayNowFormattedHandlerIdentifier,
            'sepadirectdebit': sepaFormattedHandlerIdentifier,
            'sofort': sofortFormattedHandlerIdentifier,
            'paypal': paypalFormattedHandlerIdentifier,
            'oneclick': oneClickFormattedHandlerIdentifier
        };

        //PMs that should show an 'Update Details' button if there's already a state.data for that PM stored for this context
        this.updatablePaymentMethods = ['scheme', 'ideal', 'sepadirectdebit', 'oneclick'];

        this.client = new StoreApiClient();

        const handleOnAdditionalDetails = function (state) {
            this.client.post(
                `${adyenCheckoutOptions.paymentDetailsUrl}`,
                JSON.stringify({orderId: window.orderId, stateData: state.data}),
                function (paymentAction) {
                    // TODO: clean-up
                    const paymentActionResponse = JSON.parse(paymentAction);

                    if (paymentActionResponse.isFinal) {
                        location.href = window.returnUrl;
                    }

                    try {
                        window.adyenCheckout
                            .createFromAction(paymentActionResponse.action)
                            .mount('[data-adyen-payment-action-container]');
                        $('[data-adyen-payment-action-modal]').modal({show: true});
                    } catch (e) {
                        console.log(e);
                    }
                }
            );
        }

        const { locale, originKey, environment, paymentMethodsResponse } = adyenCheckoutConfiguration;
        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            originKey,
            environment,
            showPayButton: false,
            hasHolderName: true,
            paymentMethodsResponse: JSON.parse(paymentMethodsResponse),
            onAdditionalDetails: handleOnAdditionalDetails.bind(this)
        };

        window.adyenCheckout = new AdyenCheckout(ADYEN_CHECKOUT_CONFIG);

        this.placeOrderAllowed = false;
        this.data = '';
        this.formValidator = {};

        // use this object to iterate through the paymentMethods response
        const paymentMethods = window.adyenCheckout.paymentMethodsResponse.paymentMethods;
        const storedPaymentMethods = window.adyenCheckout.paymentMethodsResponse.storedPaymentMethods;

        // Iterate through the payment methods list we got from the adyen checkout component
        paymentMethods.forEach(this.renderPaymentMethod.bind(this));
        this.formValidator[this.paymentMethodTypeHandlers.oneclick] = {};
        storedPaymentMethods.forEach(this.renderStoredPaymentMethod.bind(this));

        //Show the payment method's contents if it's selected by default
        $(`[data-adyen-payment-method-id="${validatePaymentMethod()}"]`).show();

        //Hiding component contents if there's already state.data saved for this PM
        this.updatablePaymentMethods.forEach(element => this.hideStateData(element));

        /**
         * Shows the payment method component in order to update the previously saved details
         */
        window.showPaymentMethodDetails = function () {
            $('[data-adyen-payment-container]').show();
            $('[data-adyen-update-payment-details]').hide();
        }

        /* eslint-enable no-unused-vars */
    }

    renderPaymentMethod (paymentMethod) {
        //  if the container doesn't exist don't try to render the component
        const paymentMethodContainer = $('[data-adyen-payment-method="' + this.paymentMethodTypeHandlers[paymentMethod.type] + '"]');

        // container doesn't exist, something went wrong on the template side
        // If payment method doesn't have details, just skip it
        if (!paymentMethodContainer || !paymentMethod.details) {
            return;
        }

        //Hide other payment method's contents when selecting an option
        $('[name=paymentMethodId]').on("change", function () {
            $('.adyen-payment-method-container-div').hide();
            $(`[data-adyen-payment-method-id="${$(this).val()}"]`).show();
        });

        /*Use the storedPaymentMethod object and the custom onChange function as the configuration object together*/
        const configuration = Object.assign(paymentMethod, {
            onChange: this.onPaymentMethodChange.bind(this)
        });

        if (paymentMethod.type === 'scheme') {
            configuration.enableStoreDetails = true;
        }

        try {
            const paymentMethodInstance = window.adyenCheckout
                .create(paymentMethod.type, configuration);

            paymentMethodInstance.mount(paymentMethodContainer.find('[data-adyen-payment-container]').get(0));

            this.formValidator[this.paymentMethodTypeHandlers[paymentMethod.type]] = new FormValidatorWithComponent(paymentMethodInstance);
        } catch (err) {
            console.log(err);
        }
    }

    renderStoredPaymentMethod(paymentMethod) {
        //  if the container doesn't exits don't try to render the component
        const paymentMethodContainer = $('[data-adyen-payment-method="' + this.paymentMethodTypeHandlers["oneclick"] + '"]');

        // container doesn't exist, something went wrong on the template side
        if (!paymentMethodContainer) {
            return;
        }

        //Hide other payment method's contents when selecting an option
        $('[name=paymentMethodId]').on("change", function () {
            $('.adyen-payment-method-container-div').hide();
            $(`[data-adyen-payment-method-id="${$(this).val()}"]`).show();
        });

        /*Use the storedPaymentMethod object and the custom onChange function as the configuration object together*/
        const configuration = Object.assign(paymentMethod, {
            onChange: this.onPaymentMethodChange.bind(this)
        });

        try {
            const paymentMethodInstance = window.adyenCheckout
                .create(paymentMethod.type, configuration);
            paymentMethodInstance.mount(
                paymentMethodContainer.find(`[data-adyen-stored-payment-method-id="${paymentMethod.id}"]`).get(0));

            this.formValidator[this.paymentMethodTypeHandlers.oneclick][paymentMethod.storedPaymentMethodId] = new FormValidatorWithComponent(paymentMethodInstance);
        } catch (err) {
            console.log(err);
        }
    }

    hideStateData(method) {
        let element = $(`[data-adyen-payment-method=${this.paymentMethodTypeHandlers[method]}]`);
        if (method === adyenCheckoutOptions.statedataPaymentMethod) {
            //The state data stored matches this method, show update details button
            element.find('[data-adyen-payment-container]').hide();
            element.find('[data-adyen-update-payment-details]').show();
        } else if (method === 'oneclick' && adyenCheckoutOptions.statedataPaymentMethod === 'storedPaymentMethods') {
            //The state data stored matches storedPaymentMethods, show update details button for oneclick
            $(`[data-adyen-payment-method=${this.paymentMethodTypeHandlers["oneclick"]}]`).find('[data-adyen-payment-container]').hide();
            $(`[data-adyen-payment-method=${this.paymentMethodTypeHandlers["oneclick"]}]`).find('[data-adyen-update-payment-details]').show();
        } else {
            //The state data stored does not match this method, show the form
            element.find('[data-adyen-payment-container]').show();
            element.find('[data-adyen-update-payment-details]').hide();
        }
    };

    /**
     * Reset card details
     */
    resetFields () {
        this.data = '';
    }

    onConfirmPayment (event) {
        let selectedPaymentMethod = this.getSelectedPaymentMethodHandlerIdentifyer();
        if (!(selectedPaymentMethod in this.formValidator)) {
            return true;
        }

        if (selectedPaymentMethod === this.paymentMethodTypeHandlers.oneclick) {
            let selectedStoredPaymentMethodID = this.getSelectedStoredPaymentMethodID();
            if (!(selectedStoredPaymentMethodID in this.formValidator[selectedPaymentMethod])) {
                return;
            }

            if (!this.formValidator[selectedPaymentMethod][selectedStoredPaymentMethodID].validateForm()) {
                event.preventDefault();
            }
            return;
        }

        if (!this.formValidator[selectedPaymentMethod].validateForm()) {
            event.preventDefault();
        }
    }

    onPaymentMethodChange (state) {
        if (state.isValid) {
            this.data = state.data;
            $('#adyenStateData').val(JSON.stringify(this.data));
            $('#adyenOrigin').val(window.location.origin);
            this.placeOrderAllowed = true;
        } else {
            this.placeOrderAllowed = false;
            this.resetFields();
        }
    }

    getSelectedPaymentMethodHandlerIdentifyer() {
        return $('[name=paymentMethodId]:checked').siblings('.adyen-payment-method-container-div').data('adyen-payment-method');
    }

    getSelectedStoredPaymentMethodID() {
        return $('[name=adyenStoredPaymentMethodId]:checked').val();
    }
}
