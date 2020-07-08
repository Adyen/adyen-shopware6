import Plugin from 'src/plugin-system/plugin.class';

export default class CheckoutPlugin extends Plugin {

    init() {
        /* global adyenCheckoutConfiguration, AdyenCheckout */
        /* eslint-disable no-unused-vars */

        const paymentMethodTypeHandlers = {
            'scheme': 'handler_adyen_cardspaymentmethodhandler'
        };

        var ADYEN_CHECKOUT_CONFIG = {
            locale: adyenCheckoutConfiguration.locale,
            originKey: adyenCheckoutConfiguration.originKey,
            environment: adyenCheckoutConfiguration.environment,
            showPayButton: false,
            paymentMethodsResponse: JSON.parse(adyenCheckoutConfiguration.paymentMethodsResponse),

            //Needed so the generic component does not throw errors, can be removed when the installments issue has been fixed
            paymentMethodsConfiguration: {card: {installments: []}},
            amount: {value: 1}
        };

        if (ADYEN_CHECKOUT_CONFIG) {
            window.adyenCheckout = new AdyenCheckout(ADYEN_CHECKOUT_CONFIG);
        }

        var placeOrderAllowed = false;
        var data;

        // use this object to iterate through the stored payment methods
        var paymentMethods = ADYEN_CHECKOUT_CONFIG.paymentMethodsResponse.paymentMethods;

        // Iterate through the payment methods list we got from the adyen checkout component
        paymentMethods.forEach(function (paymentMethod) {
            //  if the container doesn't exits don't try to render the component
            var paymentMethodContainer = $('[data-payment-method="' + paymentMethodTypeHandlers[paymentMethod.type] + '"]');

            // container doesn't exist, something went wrong on the template side
            if (!paymentMethodContainer.length) {
                return;
            }

            // If payment method doesn't have details, just skip it
            if (!paymentMethod.details) {
                return;
            }

            //Hide other payment method's contents when selecting an option
            $('[name=paymentMethodId]').on("change", function () {
                $('.payment-method-container-div').hide();
                $('[data-payment-method-id="' + $(this).val() + '"]').show();
            });

            // filter personal details extra fields from component and only leave the necessary ones
            paymentMethod.details = filterOutOpenInvoiceComponentDetails(paymentMethod.details);

            /*Use the storedPaymentMethod object and the custom onChange function as the configuration object together*/
            var configuration = Object.assign(paymentMethod, {
                'onChange': function (state) {
                    if (state.isValid) {
                        data = state.data;
                        placeOrderAllowed = true;
                    } else {
                        placeOrderAllowed = false;
                        resetFields();
                    }
                }
            });

            try {

                window.adyenCheckout
                    .create(paymentMethod.type, configuration)
                    .mount(paymentMethodContainer.find('[data-adyen-payment-container]').get(0));
            } catch (err) {
                console.log(err);
            }


        });

        /**
         * In the open invoice components we need to validate only the personal details and only the
         * dateOfBirth, telephoneNumber and gender if it's set in the admin
         * @param details
         * @returns {Array}
         */
        function filterOutOpenInvoiceComponentDetails(details) {
            var filteredDetails = details.map(function (parentDetail) {
                // filter only personalDetails, billingAddress, separateDeliveryAddress, deliveryAddress and consentCheckbox
                if ("personalDetails" !== parentDetail.key &&
                    "billingAddress" !== parentDetail.key &&
                    "separateDeliveryAddress" !== parentDetail.key &&
                    "deliveryAddress" !== parentDetail.key &&
                    "consentCheckbox" !== parentDetail.key
                ) {
                    return parentDetail;
                }

                if ("personalDetails" === parentDetail.key) {
                    var detailObject = parentDetail.details.map(function (detail) {
                        if ('dateOfBirth' === detail.key ||
                            'telephoneNumber' === detail.key ||
                            'gender' === detail.key) {
                            return detail;
                        }
                    });

                    if (!!detailObject) {
                        return {
                            "key": parentDetail.key,
                            "type": parentDetail.type,
                            "details": filterUndefinedItemsInArray(detailObject)
                        };
                    }
                }
            });

            return filterUndefinedItemsInArray(filteredDetails);
        }

        /**
         * Helper function to filter out the undefined items from an array
         * @param arr
         * @returns {*}
         */
        function filterUndefinedItemsInArray(arr) {
            return arr.filter(function (item) {
                return typeof item !== 'undefined';
            });
        }

        /**
         * Reset card details
         */
        function resetFields() {
            data = "";
        }

        /* eslint-enable no-unused-vars */
    }


}
