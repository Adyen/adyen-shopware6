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
import adyenConfiguration from '../configuration/adyen';

/* global adyenCheckoutConfiguration, AdyenCheckout, adyenCheckoutOptions */
/* eslint-disable no-unused-vars */
export default class CheckoutPlugin extends Plugin {

    init() {
        const confirmPaymentForm = DomAccess.querySelector(document, '#confirmPaymentForm');
        confirmPaymentForm.addEventListener('submit', this.onConfirmPaymentMethod.bind(this));

        const { locale, originKey, clientKey, environment, paymentMethodsResponse } = adyenCheckoutConfiguration;
        const ADYEN_CHECKOUT_CONFIG = {
            locale,
            originKey,
            clientKey,
            environment,
            showPayButton: false,
            hasHolderName: true,
            paymentMethodsResponse: JSON.parse(paymentMethodsResponse),
            onAdditionalDetails: this.handleOnAdditionalDetails.bind(this)
        };

        window.adyenCheckout = new AdyenCheckout(ADYEN_CHECKOUT_CONFIG);

        this.placeOrderAllowed = false;
        this.data = '';
        this.storedPaymentMethodData = {};
        this.formValidator = {};

        // use this object to iterate through the paymentMethods response
        const paymentMethods = window.adyenCheckout.paymentMethodsResponse.paymentMethods;
        const storedPaymentMethods = window.adyenCheckout.paymentMethodsResponse.storedPaymentMethods;

        // Iterate through the payment methods list we got from the adyen checkout component
        paymentMethods.forEach(this.renderPaymentMethod.bind(this));
        this.formValidator[adyenConfiguration.paymentMethodTypeHandlers.oneclick] = {};
        storedPaymentMethods.forEach(this.renderStoredPaymentMethod.bind(this));

        //Show the payment method's contents if it's selected by default
        $(`[data-adyen-payment-method-id="${validatePaymentMethod()}"]`).show();

        //Hiding component contents if there's already state.data saved for this PM
        adyenConfiguration.updatablePaymentMethods.forEach(element => this.hideStateData(element));

        /**
         * Shows the payment method component in order to update the previously saved details
         */
        window.showPaymentMethodDetails = function () {
            $('[data-adyen-payment-container]').show();
            $('[data-adyen-update-payment-details]').hide();
        }

        /* eslint-enable no-unused-vars */
    }

    handleOnAdditionalDetails (state) {
        this.client = new StoreApiClient();
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

    renderPaymentMethod (paymentMethod) {
        //  if the container doesn't exist don't try to render the component
        const paymentMethodContainer = $('[data-adyen-payment-method="' + adyenConfiguration.paymentMethodTypeHandlers[paymentMethod.type] + '"]');

        if (adyenConfiguration.componentsWithPayButton.includes(paymentMethod.type)) {
            return;
        }

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
            const paymentMethodInstance = window.adyenCheckout.create(paymentMethod.type, configuration);
            paymentMethodInstance.mount(paymentMethodContainer.find('[data-adyen-payment-container]').get(0));
            this.formValidator[adyenConfiguration.paymentMethodTypeHandlers[paymentMethod.type]] = new FormValidatorWithComponent(paymentMethodInstance);
        } catch (err) {
            console.log(paymentMethod.type + err);
        }
    }

    renderStoredPaymentMethod(paymentMethod) {
        //  if the container doesn't exits don't try to render the component
        const paymentMethodContainer = $('[data-adyen-payment-method="' + adyenConfiguration.paymentMethodTypeHandlers["oneclick"] + '"]');

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
            onChange: this.onStoredPaymentMethodChange.bind(this)
        });

        try {
            const paymentMethodInstance = window.adyenCheckout
                .create(paymentMethod.type, configuration);
            paymentMethodInstance.mount(
                paymentMethodContainer.find(`[data-adyen-stored-payment-method-id="${paymentMethod.id}"]`).get(0)
            );

            paymentMethodContainer.data('paymentMethodInstance', paymentMethodInstance);

            this.formValidator[adyenConfiguration.paymentMethodTypeHandlers.oneclick][paymentMethod.storedPaymentMethodId] = new FormValidatorWithComponent(paymentMethodInstance);
        } catch (err) {
            console.log(paymentMethod.type + err);
        }
    }

    hideStateData(method) {
        let element = $(`[data-adyen-payment-method=${adyenConfiguration.paymentMethodTypeHandlers[method]}]`);
        if (method === adyenCheckoutOptions.statedataPaymentMethod) {
            //The state data stored matches this method, show update details button
            element.find('[data-adyen-payment-container]').hide();
            element.find('[data-adyen-update-payment-details]').show();
        } else if (method === 'oneclick' && adyenCheckoutOptions.statedataPaymentMethod === 'storedPaymentMethods') {
            //The state data stored matches storedPaymentMethods, show update details button for oneclick
            $(`[data-adyen-payment-method=${adyenConfiguration.paymentMethodTypeHandlers["oneclick"]}]`).find('[data-adyen-payment-container]').hide();
            $(`[data-adyen-payment-method=${adyenConfiguration.paymentMethodTypeHandlers["oneclick"]}]`).find('[data-adyen-update-payment-details]').show();
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

    onConfirmPaymentMethod (event) {
        let selectedPaymentMethod = this.getSelectedPaymentMethodHandlerIdentifyer();
        if (!(selectedPaymentMethod in this.formValidator)) {
            return true;
        }

        if (selectedPaymentMethod === adyenConfiguration.paymentMethodTypeHandlers.oneclick) {
            let selectedStoredPaymentMethodID = this.getSelectedStoredPaymentMethodID();
            if (!selectedStoredPaymentMethodID) {
                event.preventDefault();
                return;
            }

            $('#adyenStateData').val(JSON.stringify(
                this.storedPaymentMethodData[selectedStoredPaymentMethodID]
            ));

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

    onStoredPaymentMethodChange (state) {
        if (!state || !state.data || !state.data.paymentMethod) {
            return;
        }
        let storedPaymentMethodId = state.data.paymentMethod.storedPaymentMethodId;
        if (state.isValid) {
            this.storedPaymentMethodData[storedPaymentMethodId] = state.data;
            $('#adyenStateData').val(JSON.stringify(state.data));
            $('#adyenOrigin').val(window.location.origin);
            this.placeOrderAllowed = true;
        } else {
            this.placeOrderAllowed = false;
            this.storedPaymentMethodData[storedPaymentMethodId] = '';
        }
    }

    getSelectedPaymentMethodHandlerIdentifyer() {
        return $('[name=paymentMethodId]:checked').siblings('.adyen-payment-method-container-div').data('adyen-payment-method');
    }

    getSelectedStoredPaymentMethodID() {
        return $('[name=adyenStoredPaymentMethodId]:checked').val();
    }
}
