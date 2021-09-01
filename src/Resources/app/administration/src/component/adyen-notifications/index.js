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
import template from './adyen-notifications.html.twig';


Component.register('adyen-notifications', {
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
            notifications: [],
            columns: [
                { property: 'pspReference', label: this.$tc('adyen.columnHeaders.pspReference') },
                { property: 'eventCode', label: this.$tc('adyen.columnHeaders.event') },
                { property: 'success', label: this.$tc('adyen.columnHeaders.success') },
                { property: 'amount', label: this.$tc('adyen.columnHeaders.amount') },
                { property: 'status', label: this.$tc('adyen.columnHeaders.status') },
                { property: 'createdAt', label: this.$tc('adyen.columnHeaders.created') },
                { property: 'updatedAt', label: this.$tc('adyen.columnHeaders.updated') },
            ],
            showWidget: false,
        }
    },

    methods: {
        fetchNotifications() {
            this.adyenService.fetchNotifications(this.order.id).then((res) => {
                this.notifications = res;
            });
        }
    },

    beforeMount() {
        this.showWidget = this.adyenService.isAdyenOrder(this.order);
        if (this.showWidget) {
            this.fetchNotifications();
        }
    }
})