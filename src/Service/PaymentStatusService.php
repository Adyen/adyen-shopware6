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
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\PaymentResponse;
use Adyen\Model\Checkout\PaymentResponseAction;
use Adyen\Model\Checkout\ResponsePaymentMethod;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use JsonException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
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

    /**
     * @param OrderTransactionEntity $orderTransaction
     * @return array
     * @throws MissingDataException
     * @throws JsonException
     */
    public function getPaymentStatusWithOrderTransaction(OrderTransactionEntity $orderTransaction): array
    {
        $paymentResponse = $this->paymentResponseService->getWithOrderTransaction($orderTransaction);

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

        $checkoutPaymentResponse = $this->transformResponseData($responseData);

        $result = $this->paymentResponseHandler->handlePaymentResponse(
            $checkoutPaymentResponse,
            $orderTransaction
        );

        return $this->paymentResponseHandler->handleAdyenApis($result);
    }

    public function getWithOrderId(string $orderId, SalesChannelContext $context): array
    {
        $paymentResponse = $this->paymentResponseService->getWithOrderId($orderId, $context->getContext());

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

        $checkoutPaymentResponse = $this->transformResponseData($responseData);

        $result = $this->paymentResponseHandler->handlePaymentResponse(
            $checkoutPaymentResponse,
            $paymentResponse->getOrderTransaction()
        );

        return $this->paymentResponseHandler->handleAdyenApis($result);
    }

    private function transformResponseData(array $responseData): PaymentResponse
    {
        $checkoutPaymentResponse = new PaymentResponse($responseData);

        if (array_key_exists('action', $responseData)) {
            $action = new PaymentResponseAction($responseData['action']);
            $checkoutPaymentResponse->setAction($action);
        }

        if (array_key_exists('amount', $responseData)) {
            $amount = new Amount($responseData['amount']);
            $checkoutPaymentResponse->setAmount($amount);
        }

        if (array_key_exists('paymentMethod', $responseData)) {
            $paymentMethod = new ResponsePaymentMethod($responseData['paymentMethod']);
            $checkoutPaymentResponse->setPaymentMethod($paymentMethod);
        }

        return $checkoutPaymentResponse;
    }
}
