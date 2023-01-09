<?php declare(strict_types=1);
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
use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use JsonException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

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

    /**
     * @deprecated
     * @param OrderTransactionEntity $orderTransaction
     * @return array
     * @throws MissingDataException
     * @throws JsonException
     */
    public function getPaymentStatusWithOrderTransaction(OrderTransactionEntity $orderTransaction): array
    {
        $paymentResponse = $this->paymentResponseService->getWithOrderTransaction($orderTransaction->getId());

        if (empty($paymentResponse)) {
            throw new MissingDataException(
                'Payment response cannot be found for order number: ' .
                $orderTransaction->getOrder()->getOrderNumber() . '!'
            );
        }

        $responseData = json_decode($paymentResponse->getResponse(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException(
                'Payment response is an invalid JSON for order number: ' .
                $orderTransaction->getOrder()->getOrderNumber() . '!'
            );
        }

        $result = $this->paymentResponseHandler->handlePaymentResponse(
            $responseData,
            $orderTransaction->getId()
        );

        return $this->paymentResponseHandler->handleAdyenApis($result);
    }

    public function getWithPaymentReference(string $paymentReference): array
    {
        $paymentResponse = $this->paymentResponseService->getWithPaymentReference($paymentReference);

        if (empty($paymentResponse)) {
            throw new MissingDataException(
                'Payment response cannot be found for payment: ' .
                $paymentReference
            );
        }

        $responseData = json_decode($paymentResponse->getResponse(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException(
                'Payment response is an invalid JSON for payment: ' .
                $paymentReference
            );
        }

        $result = $this->paymentResponseHandler->handlePaymentResponse(
            $responseData,
            null,
            $paymentReference
        );

        return $this->paymentResponseHandler->handleAdyenApis($result);
    }

    public function getWithOrderId(string $orderId): array
    {
        $paymentResponse = $this->paymentResponseService->getWithOrderId($orderId);

        if (empty($paymentResponse)) {
            throw new MissingDataException(
                'Payment response cannot be found for order id: ' . $orderId . '!'
            );
        }

        $responseData = json_decode($paymentResponse->getResponse(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException(
                'Payment response is an invalid JSON for order number: ' .
                $paymentResponse->getOrderTransaction()->getOrder()->getOrderNumber() . '!'
            );
        }

        $result = $this->paymentResponseHandler->handlePaymentResponse(
            $responseData,
            $paymentResponse->getOrderTransaction()->getId()
        );

        return $this->paymentResponseHandler->handleAdyenApis($result);
    }
}
