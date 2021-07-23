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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

const ApiService = Shopware.Classes.ApiService;
const { Application } = Shopware;

class ApiClient extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'adyen') {
        super(httpClient, loginService, apiEndpoint);
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

    postRefund(orderId) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .post(this.getApiBasePath() + '/refunds', {orderId: orderId}, {
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            }).catch((error) => {
                console.error('An error occurred during post refund request: ' + error.message);
                throw error;
            });
    }
}

Application.addServiceProvider('adyenService', (container) => {
    const initContainer = Application.getContainer('init');
    return new ApiClient(initContainer.httpClient, container.loginService);
});
