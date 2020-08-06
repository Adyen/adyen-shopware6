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

        //PMs that should show an 'Update Details' button if there's already a state.data for that PM stored for this context
        const updatablePaymentMethods = ['scheme'];

        this.client = new StoreApiClient();

        let handleOnAdditionalDetails = function (state) {
            this.client.post(
                `${adyenCheckoutOptions.paymentDetailsUrl}`,
                JSON.stringify({'orderId': window.orderId, 'stateData': state.data}),
                function (paymentAction) {
                    // TODO: clean-up
                    const paymentActionResponse = JSON.parse(paymentAction);

                    if (paymentActionResponse.isFinal === true) {
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

            //Show the payment method's contents if it's selected by default
            if ($('[data-payment-method-id]').data('payment-method-id') == $('[name=paymentMethodId]:checked').val()) {
                $('[data-payment-method-id]').show();
            }

            //Hide other payment method's contents when selecting an option
            $('[name=paymentMethodId]').on("change", function () {
                $('.adyen-payment-method-container-div').hide();
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

            //Hiding component contents if there's already state.data saved for this PM
            if (updatablePaymentMethods.includes(paymentMethod.type) && adyenCheckoutOptions.statedataPaymentMethod === paymentMethod.type) {
                console.log('includes');
                $('[data-adyen-payment-container]').hide();
                $('[data-adyen-update-payment-details]').show();
            }


        });

        /**
         * Reset card details
         */
        function resetFields() {
            data = "";
        }

        /**
         * Shows the payment method component in order to update the previously saved details
         */
        window.showPaymentMethodDetails = function () {
            $('[data-adyen-payment-container]').show();
            $('[data-adyen-update-payment-details]').hide();
        }

        /* eslint-enable no-unused-vars */
    }
}
