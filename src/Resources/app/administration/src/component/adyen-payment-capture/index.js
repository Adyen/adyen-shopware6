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
            allowCapture: false,
            captureEnabled: false,
            errorOccurred: false,
            isLoading: true,
            showWidget: false
        };
    },


    methods: {
        openModal() {
            this.showModal = true;
        },

        onCloseModal() {
            this.showModal = false;
        },

        onSubmitCapture() {
            if(this.isLoading === true) {
                return;
            }

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

        isManualCaptureEnabled() {
            this.isLoading = true;
            this.adyenService.isManualCaptureEnabled(this.order.id).then((res) => {
                this.captureEnabled = res;
                this.showWidget = this.adyenService.isAdyenOrder(this.order) && this.captureEnabled;
            }).catch(() => {
                this.errorOccurred = true;
                this.captureEnabled = false;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        isCaptureAllowed() {
            this.isLoading = true;
            this.adyenService.isCaptureAllowed(this.order.id).then((res) => {
                this.allowCapture = res;
            }).catch(() => {
                this.errorOccurred = true;
                this.allowCapture = false;
            }).finally(() => {
                this.isLoading = false;
            });
        }
    },

    beforeMount() {
        this.isManualCaptureEnabled();
        this.fetchCaptureRequests();
    }
})
