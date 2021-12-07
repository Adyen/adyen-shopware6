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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

const { Component, Mixin } = Shopware;
import template from './adyen-refund.html.twig';
import './adyen-refund.scss';

Component.register('adyen-refund', {
    template,

    inject: ['adyenService'],

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
            inputAmount: 0,
            showModal: false,
            refunds: [],
            allowRefund: true,
            isLoadingTable: true,
            errorOccurred: false,
            isLoadingRefund: false,
            showWidget: true,
        };
    },

    methods: {
        openModal() {
            this.showModal = true;
        },

        onCloseModal() {
            this.showModal = false;
        },

        onRefund() {
            this.isLoadingRefund = true;
            this.adyenService.postRefund(this.order.id, this.inputAmount).then((res) => {
                if (res.success) {
                    this.fetchRefunds();
                    this.createNotificationSuccess({
                        title: this.$tc('adyen.refundTitle'),
                        message: this.$tc('adyen.refundSuccessful')
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('adyen.refundTitle'),
                        message: this.$tc(res.message ? res.message : 'adyen.refundError')
                    });
                }
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('adyen.refundTitle'),
                    message: this.$tc('adyen.refundError')
                });
            }).finally(() => {
                this.isLoadingRefund = false;
                this.showModal = false;
            });
        },

        fetchRefunds() {
            this.isLoadingTable = true;
            this.adyenService.getRefunds(this.order.id).then((res) => {
                this.refunds = res;
                this.isRefundAllowed();
            }).catch(() => {
                this.errorOccurred = true;
                this.refunds = [];
            }).finally(() => {
                this.isLoadingTable = false;
            });
        },

        isRefundAllowed() {
            let refundedAmount = 0;
            for (const refund of this.refunds) {
                if (refund.status !== 'Failed') {
                    refundedAmount += refund.rawAmount;
                }
            }

            this.allowRefund = this.order.amountTotal > (refundedAmount / 100);
        },

        isAdyenOrder() {
            const orderTransactions = this.order.transactions;
            let isAdyen = false;
            for (let i = 0; i < orderTransactions.length; i++) {
                if (orderTransactions[i].customFields !== undefined) {
                    if (orderTransactions[i].customFields.originalPspReference !== undefined) {
                        isAdyen = true;
                    }
                }
            }

            this.showWidget = isAdyen;
        }
    },

    beforeMount() {
        this.isAdyenOrder();
        if (this.showWidget) {
            this.fetchRefunds();
        }
    }
})
