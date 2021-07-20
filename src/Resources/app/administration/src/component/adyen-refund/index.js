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

const { Component } = Shopware;
import template from './adyen-refund.html.twig';
import './adyen-refund.scss';

Component.register('adyen-refund', {
    template,

    inject: ['adyenService'],

    props: {
        order: {
            type: Object,
            required: true
        },
    },

    data() {
        return {
            columns: [
                { property: 'pspReference', label: 'PSP Reference' },
                { property: 'amount', label: 'Amount' },
                { property: 'source', label: 'Source' },
                { property: 'status', label: 'Status' },
                { property: 'createdAt', label: 'Created' },
                { property: 'updatedAt', label: 'Updated' }
            ],
            showModal: false,
            refunds: [],
            isLoadingTable: true
        };
    },

    methods: {
        openModal() {
            this.showModal = true;
        },

        onCloseModal() {
            this.showModal = false;
        },

        fetchRefunds() {
            this.adyenService.getRefunds(this.order.orderNumber).then((res) => {
                this.refunds = res;
                this.isLoadingTable = false;
            });
        }
    },

    beforeMount() {
        this.fetchRefunds();
    }
})
