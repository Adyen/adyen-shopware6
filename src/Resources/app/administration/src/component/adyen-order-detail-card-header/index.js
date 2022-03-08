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

const { Component } = Shopware;
import template from './adyen-order-detail-card-header.html.twig';
import './adyen-order-detail-card-header.scss';

Component.register('adyen-order-detail-card-header', {
    template,

    props: {
        actionButtonDisabled: {
            type: Boolean,
            required: true
        },
        dataSourceEmpty: {
            type: Boolean,
            required: true
        },
        dataSourceEmptyMessage: {
            type: String,
            default: ''
        },
        modalTitle: {
            type: String,
            default: ''
        },
        modalAmountModel: {
            type: String,
            required: true
        },
        isLoading: {
            type: Boolean,
            required: true
        }
    },
    methods: {
        submitModal() {
            this.$emit('onSubmitModal');
        }
    }
})