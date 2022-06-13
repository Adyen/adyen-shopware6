/**
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

const ApiService = Shopware.Classes.ApiService;
const { Application } = Shopware;

class ApiClient extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'adyen') {
        super(httpClient, loginService, apiEndpoint);
        this.headers = this.getBasicHeaders({});
    }

    check(values) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .post(`_action/${this.getApiBasePath()}/verify`, values,{
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    capture(orderId) {
        return this.httpClient.post(
            this.getApiBasePath() + '/capture',
            { orderId },
            { headers: this.headers }
        ).then((response) => {
            return ApiService.handleResponse(response);
        }).catch((error) => {
            console.error('An error occurred during capture request: ' + error.message);
            throw error;
        });
    }

    getCaptureRequests(orderId) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .get(this.getApiBasePath() + '/orders/' + orderId + '/captures', {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            }).catch((error) => {
                console.error('An error occurred during capture request: ' + error.message);
                throw error;
            });
    }

    getRefunds(orderId) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .get(this.getApiBasePath() + '/orders/' + orderId + '/refunds', {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            }).catch((error) => {
                console.error('An error occurred during refunds request: ' + error.message);
                throw error;
            });
    }

    postRefund(orderId, refundAmount) {
        const headers = this.getBasicHeaders({});
        return this.httpClient
            .post(this.getApiBasePath() + '/refunds', {orderId: orderId, refundAmount: refundAmount}, {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);

            }).catch((error) => {
                console.error('An error occurred during post refund request: ' + error.message);
                throw error;
            });
    }

    fetchNotifications(orderId) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .get(this.getApiBasePath() + '/orders/' + orderId + '/notifications', {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            }).catch((error) => {
                console.error('An error occurred: ' + error.message);
                throw error;
            });
    }

    isAdyenOrder(order) {
        const orderTransactions = order.transactions;
        let isAdyen = false;
        for (let i = 0; i < orderTransactions.length; i++) {
            if (orderTransactions[i].customFields !== undefined) {
                if (orderTransactions[i].customFields.originalPspReference !== undefined) {
                    isAdyen = true;
                }
            }
        }

        return isAdyen;
    }

    getPaymentDetails(orderId) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .get(this.getApiBasePath() + '/orders/' + orderId + '/payment-details', {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            }).catch((error) => {
                console.error('An error occurred: ' + error.message);
                throw error;
            });
    }
}

Application.addServiceProvider('adyenService', (container) => {
    const initContainer = Application.getContainer('init');
    return new ApiClient(initContainer.httpClient, container.loginService);
});
