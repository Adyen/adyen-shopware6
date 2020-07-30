<?php

declare(strict_types=1);
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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

class PaymentStatusService
{
    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    public function __construct(
        PaymentResponseService $paymentResponseService
    ) {
        $this->paymentResponseService = $paymentResponseService;
    }

    public function getPaymentStatusWithOrderId(string $orderId): array
    {
        $paymentResponse = $this->paymentResponseService->getWithOrderId($orderId);
        $responseData = json_decode($paymentResponse->getResponse(), true);
        $action = [];

        if ($responseData['action']) {
            $action = [$responseData['action']];
        }

        $paymentStatus = [
            'paymentResponseResultCode' => $paymentResponse->getResultCode(),
            'action' => $action
        ];

        return $paymentStatus;
    }
}
