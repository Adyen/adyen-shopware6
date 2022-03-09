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

import './service/adyenService';
import './component/adyen-config-check-button';
import './component/adyen-payment-capture';
import './component/adyen-refund';
import './component/adyen-notifications';
import './sw-order-detail-base-override/index';
import './component/entity/sw-entity-single-select-override';

import localeEnGb from './snippet/en_GB.json';

Shopware.Locale.extend('en-GB', localeEnGb);
