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
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
const { Component } = Shopware;
const { Criteria } = Shopware.Data;

export default class AdyenVoucherPlugin extends Plugin {
    init() {
        console.log('are you here?');
        const { orderId } = adyenVoucherDetails;
        console.log(orderId);
        const criteria = new Criteria();
        criteria.addFilter(Criteria.equals('order_transaction.custom_fields.action.type', 'voucher'));
        return criteria;
    }
}
