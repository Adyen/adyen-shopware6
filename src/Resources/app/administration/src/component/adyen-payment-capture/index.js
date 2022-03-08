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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

const { Component, Mixin } = Shopware;
import template from './adyen-payment-capture.html.twig';
import './adyen-payment-capture.scss';

Component.register('adyen-payment-capture', {
    template,

    inject: ['adyenService', 'systemConfigApiService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        order: {
            type: Object,
            required: true
        },
    },

    data() {
        return {
            columns: [
                { property: 'pspReference', label: this.$tc('adyen.columnHeaders.pspReference') },
                { property: 'amount', label: this.$tc('adyen.columnHeaders.amount') },
                { property: 'status', label: this.$tc('adyen.columnHeaders.status') },
                { property: 'createdAt', label: this.$tc('adyen.columnHeaders.created') },
                { property: 'updatedAt', label: this.$tc('adyen.columnHeaders.updated') }
            ],
            showModal: false,
            captureRequests: [],
            allowCapture: true,
            captureEnabled: false,
            errorOccurred: false,
            isLoading: true,
            showWidget: false,
        };
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            return this.systemConfigApiService.getValues('AdyenPaymentShopware6.config')
                .then((response) => {
                    this.captureEnabled = response['AdyenPaymentShopware6.config.manualCaptureEnabled'] || null;
                }).finally(() => {
                    this.isLoading = false;
                    this.showWidget = this.adyenService.isAdyenOrder(this.order) && this.captureEnabled;
                });
        },
        openModal() {
            this.showModal = true;
        },

        onCloseModal() {
            this.showModal = false;
        },

        onSubmitCapture() {
            this.isLoading = true;
            this.adyenService.capture(this.order.id).then(res => {
                if (res.success) {
                    this.fetchCaptureRequests();
                    this.createNotificationSuccess({
                        title: this.$tc('adyen.adyenPaymentCaptureTitle'),
                        message: this.$tc('adyen.captureSuccessful')
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('adyen.adyenPaymentCaptureTitle'),
                        message: this.$tc(res.message ? res.message : 'adyen.error')
                    });
                }
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('adyen.adyenPaymentCaptureTitle'),
                    message: this.$tc('adyen.error')
                });
            }).finally(() => {
                this.isLoading = false;
                this.showModal = false;
            });
        },

        fetchCaptureRequests() {
            this.isLoading = true;
            this.adyenService.getCaptureRequests(this.order.id).then((res) => {
                this.captureRequests = res;
                this.isCaptureAllowed();
            }).catch(() => {
                this.errorOccurred = true;
                this.captureRequests = [];
            }).finally(() => {
                this.isLoading = false;
            });
        },

        isCaptureAllowed() {
            let capturableTransactions = this.getAuthorizedAdyenOrderTransaction();
            let capturePending = this.captureRequests.filter(request => {
                return "Pending Webhook" === request.status;
            });

            this.allowCapture = capturableTransactions.length > 0 && capturePending.length === 0;
        },

        getAuthorizedAdyenOrderTransaction() {
            return this.order.transactions.filter(transaction => {
                const isAdyenPayment = 'originalPspReference' in transaction.customFields;
                const isAuthorized = 'Authorized' === transaction.stateMachineState.name;

                return isAdyenPayment && isAuthorized;
            });
        }
    },

    beforeMount() {
        this.fetchCaptureRequests();
    }
})
