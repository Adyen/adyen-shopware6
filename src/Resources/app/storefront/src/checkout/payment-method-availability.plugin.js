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

/* global adyenCheckoutConfiguration, activeShippingAddress */
/**
 * Hides wallet payment methods (e.g. Apple Pay) in the payment method list
 * when the Adyen Web component reports that they are not available on the shopper's device,
 * so they cannot be selected before the availability check fails.
 */
export default class PaymentMethodAvailabilityPlugin extends Plugin {

    async init() {
        const paymentMethodType = this.el.dataset.adyenAvailabilityCheck;

        if (!window.AdyenWeb || typeof adyenCheckoutConfiguration === 'undefined') {
            return;
        }

        const countryCode = typeof activeShippingAddress !== 'undefined' ? activeShippingAddress.country : null;
        if (!countryCode) {
            return;
        }

        let paymentMethodInstance;
        try {
            const {AdyenCheckout, createComponent} = window.AdyenWeb;
            const {locale, clientKey, environment} = adyenCheckoutConfiguration;
            const checkout = await AdyenCheckout({locale, clientKey, environment, countryCode});
            paymentMethodInstance = createComponent(paymentMethodType, checkout, {});
        } catch (e) {
            // Checkout setup failure says nothing about the device, keep the payment method visible
            console.log('Availability check for ' + paymentMethodType + ' could not be performed', e);
            return;
        }

        if (!('isAvailable' in paymentMethodInstance)) {
            return;
        }

        try {
            await paymentMethodInstance.isAvailable();
        } catch (e) {
            console.log(paymentMethodType + ' is not available', e);
            const wasSelected = this.el.checked;
            this.hidePaymentMethod();
            if (wasSelected) {
                this.selectFirstAvailablePaymentMethod();
            }
        }
    }

    hidePaymentMethod() {
        const paymentMethodElement = this.el.closest('.payment-method') || this.el.parentElement;
        paymentMethodElement.style.display = 'none';
        this.el.disabled = true;
    }

    /**
     * Switches the shopper to the first other payment method in the list.
     * The change event triggers Shopware's auto submit of the payment form, which saves the selection.
     */
    selectFirstAvailablePaymentMethod() {
        const form = this.el.form;
        if (!form) {
            return;
        }

        const nextPaymentMethod = Array.from(form.querySelectorAll('input[name="paymentMethodId"]'))
            .find(input => input !== this.el && !input.disabled);
        if (!nextPaymentMethod) {
            return;
        }

        nextPaymentMethod.checked = true;
        nextPaymentMethod.dispatchEvent(new Event('change', {bubbles: true}));
    }
}
