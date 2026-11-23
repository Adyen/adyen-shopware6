/*
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 */

/**
 * Allowed capture delay for Delayed capture, the same on the plugin configuration page, on the payment method page
 * and on the server (CaptureConfigurationService::MIN_DELAY_DAYS / MAX_DELAY_DAYS).
 */
export const MIN_CAPTURE_DELAY_DAYS = 1;
export const MAX_CAPTURE_DELAY_DAYS = 7;

/**
 * @param {*} value
 * @returns {boolean} True for a whole number from 1 to 7.
 */
export function isValidCaptureDelayDays(value) {
    const days = Number(value);

    return !isEmptyCaptureValue(value)
        && Number.isInteger(days)
        && days >= MIN_CAPTURE_DELAY_DAYS
        && days <= MAX_CAPTURE_DELAY_DAYS;
}

/**
 * @param {*} value
 * @returns {boolean} True for a whole number of at least 1 second.
 */
export function isValidCaptureRetryInterval(value) {
    const seconds = Number(value);

    return !isEmptyCaptureValue(value) && Number.isInteger(seconds) && seconds >= 1;
}

/**
 * @param {*} value
 * @returns {boolean} True if the field has no value (it inherits).
 */
export function isEmptyCaptureValue(value) {
    return value === null || value === undefined || value === '';
}
