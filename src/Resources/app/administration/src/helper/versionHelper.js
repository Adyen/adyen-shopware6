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
 * Copyright (c) 2023 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */
let exports = {};

exports.isVersionOlderThan65 = () => {
    function semverCompare(a, b) {
        if (a.startsWith(b + "-")) {
            return -1
        } else if (b.startsWith(a + "-")) {
            return  1
        }

        return a.localeCompare(b, undefined, { numeric: true, sensitivity: "case", caseFirst: "upper" })
    };

    const version = Shopware.Context.app.config.version;

    if (semverCompare(version, "6.5.0.0") === -1) {
        return true
    } else {
        return false;
    }
};

export default exports;
