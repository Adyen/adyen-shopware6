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
                { property: 'pspReference', label: 'Adyen Reference' },
                { property: 'amount', label: 'Amount' },
                { property: 'source', label: 'Source' },
                { property: 'status', label: 'Status' },
                { property: 'createdAt', label: 'Created' },
                { property: 'updatedAt', label: 'Updated' }
            ],
            showModal: false,
            refunds: [],
            allowRefund: true,
            isLoadingTable: true,
            errorOccurred: false,
            isLoadingRefund: false,
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
            this.adyenService.postRefund(this.order.orderNumber).then((res) => {
                if (res.success) {
                    this.fetchRefunds();
                    // TODO: USE tc
                    this.createNotificationSuccess({
                        title: 'Refund submitted',
                        message: 'A refund has been successfully submitted'
                    });
                } else {
                    // TODO: USE tc
                    this.createNotificationError({
                        title: 'An error has occurred',
                        message: this.$tc(res.message ? res.message : 'An unexpected error occurred during refund submission.')
                    });
                }
            }).catch((error) => {
                this.createNotificationError({
                    // TODO: USE tc
                    title: 'An error has occurred',
                    message: 'An unexpected error occurred during refund submission.'
                });
            }).finally(() => {
                this.isLoadingRefund = false;
                this.showModal = false;
            });
        },

        fetchRefunds() {
            this.isLoadingTable = true;
            this.adyenService.getRefunds(this.order.orderNumber).then((res) => {
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
        }
    },

    beforeMount() {
        this.fetchRefunds();
    }
})
