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

use Adyen\Exception\MissingDataException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PaymentStatusService
{
    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;

    public function __construct(
        PaymentResponseService $paymentResponseService,
        PaymentResponseHandler $paymentResponseHandler
    ) {
        $this->paymentResponseService = $paymentResponseService;
        $this->paymentResponseHandler = $paymentResponseHandler;
    }

    public function getPaymentStatusWithOrderId(string $orderId, SalesChannelContext $context): array
    {
        $paymentResponse = $this->paymentResponseService->getWithOrderId($orderId, $context->getToken());

        if (empty($paymentResponse)) {
            throw new MissingDataException('Payment response cannot be found for order id: ' . $orderId . '!');
        }

        $responseData = json_decode($paymentResponse->getResponse(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \JsonException('Payment response is an invalid JSON for order id: ' . $orderId . '');
        }

        $result = $this->paymentResponseHandler->handlePaymentResponse(
            $responseData,
            $paymentResponse->getOrderNumber(),
            $context
        );

        return $this->paymentResponseHandler->handleAdyenApis($result);
    }
}
