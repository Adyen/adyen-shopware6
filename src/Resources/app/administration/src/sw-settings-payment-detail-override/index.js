import template from './sw-settings-payment-detail.html.twig';

// Shopware 6.6 does not require a technical name, and the plugin does not set one for every Adyen payment method,
// so the payment method is recognized by its handler.
const ADYEN_HANDLER_PREFIX = 'Adyen\\Shopware\\Handlers\\';

/**
 * Adds the "Adyen - Capture Settings" card to the detail page of Adyen payment methods and saves it together with
 * the payment method (spec §10.2).
 */
Shopware.Component.override('sw-settings-payment-detail', {
    template,

    computed: {
        isAdyenPaymentMethod() {
            const handlerIdentifier = this.paymentMethod?.handlerIdentifier;

            return typeof handlerIdentifier === 'string' && handlerIdentifier.startsWith(ADYEN_HANDLER_PREFIX);
        }
    },

    methods: {
        onSave() {
            const captureConfiguration = this.$refs.adyenCaptureConfiguration;
            if (!captureConfiguration) {
                return this.$super('onSave');
            }

            return captureConfiguration.save().then(() => this.$super('onSave'));
        }
    }
});
