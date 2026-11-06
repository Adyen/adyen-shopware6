import template from './sw-settings-payment-detail.html.twig';

const ADYEN_TECHNICAL_NAME_PREFIX = 'adyen_payment-';

/**
 * Adds the "Adyen - Capture Settings" card to the detail page of Adyen payment methods and saves it together with
 * the payment method (spec §10.2).
 */
Shopware.Component.override('sw-settings-payment-detail', {
    template,

    computed: {
        isAdyenPaymentMethod() {
            const technicalName = this.paymentMethod?.technicalName;

            return typeof technicalName === 'string' && technicalName.startsWith(ADYEN_TECHNICAL_NAME_PREFIX);
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
