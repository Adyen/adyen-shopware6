import Plugin from 'src/plugin-system/plugin.class';
import StoreApiClient from 'src/service/store-api-client.service';

/* global adyenCheckoutConfiguration, AdyenCheckout, adyenCheckoutOptions */
/* eslint-disable no-unused-vars */
export default class CheckoutPlugin extends Plugin {

    init() {

        const formattedHandlerIdentifier = 'handler_adyen_cardspaymentmethodhandler';

        const paymentMethodTypeHandlers = {
            'scheme': formattedHandlerIdentifier
        };

        // TODO ask Ricardo if there is a better way
        this.client = new StoreApiClient();

        let handleOnAdditionalDetails = function (state) {
            this.client.post(
                `${adyenCheckoutOptions.paymentDetailsUrl}`,
                JSON.stringify({'orderId': window.orderId, 'stateData': state.data}),
                this.handlePaymentAction.bind(this)
            );
        }

        var ADYEN_CHECKOUT_CONFIG = {
            locale: adyenCheckoutConfiguration.locale,
            originKey: adyenCheckoutConfiguration.originKey,
            environment: adyenCheckoutConfiguration.environment,
            showPayButton: false,
            paymentMethodsResponse: JSON.parse(adyenCheckoutConfiguration.paymentMethodsResponse),
            onAdditionalDetails: handleOnAdditionalDetails.bind(this)
        };

        window.adyenCheckout = new AdyenCheckout(ADYEN_CHECKOUT_CONFIG);

        var placeOrderAllowed = false;
        var data;

        // use this object to iterate through the stored payment methods
        var paymentMethods = window.adyenCheckout.paymentMethodsResponse.paymentMethods;

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


            /*Use the storedPaymentMethod object and the custom onChange function as the configuration object together*/
            var configuration = Object.assign(paymentMethod, {
                'onChange': function (state) {
                    if (state.isValid) {
                        data = state.data;
                        $('#adyenStateData').val(JSON.stringify(data));
                        $('#adyenOrigin').val(window.location.origin);
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
         * Reset card details
         */
        function resetFields() {
            data = "";
        }

        /* eslint-enable no-unused-vars */
    }


}
