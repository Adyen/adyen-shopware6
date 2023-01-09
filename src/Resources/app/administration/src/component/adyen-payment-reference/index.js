const { Component } = Shopware;
import template from './adyen-payment-reference.html.twig';

Component.register('adyen-payment-reference', {
    template,

    inject: ['adyenService'],

    props: {
        orderId: {
            type: String,
            required: true
        }
    },

    mounted() {
        this.getPaymentResponse();
    },

    data () {
        return {
            refAvailable: false,
            paymentReference: null,
            pspReference: null,
            env: 'test'
        }
    },

    computed: {
        caLink() {
            return 'https://ca-' + this.env + '.adyen.com/ca/ca/accounts/showTx.shtml?pspReference='
                + this.pspReference;
        }
    },

    methods: {
        getPaymentResponse() {
            this.adyenService.getPaymentDetails(this.orderId).then(res => {
                this.paymentReference = res.paymentReference;
                this.refAvailable = !!res.paymentReference;
                this.pspReference = res.pspReference;
                this.env = res.environment;
            });

        }
    }
})