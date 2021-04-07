<?php
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

use Adyen\AdyenException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandlerResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class PaymentDetailsService
{
    /**
     * @var CheckoutService
     */
    private $checkoutService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;

    /**
     * PaymentDetailsService constructor.
     *
     * @param LoggerInterface $logger
     * @param CheckoutService $checkoutService
     * @param PaymentResponseHandler $paymentResponseHandler
     */
    public function __construct(
        LoggerInterface $logger,
        CheckoutService $checkoutService,
        PaymentResponseHandler $paymentResponseHandler
    ) {
        $this->logger = $logger;
        $this->checkoutService = $checkoutService;
        $this->paymentResponseHandler = $paymentResponseHandler;
    }

    /**
     * @param array $details
     * @param OrderTransactionEntity $orderTransaction
     * @return PaymentResponseHandlerResult
     * @throws PaymentFailedException
     */
    public function getPaymentDetails(
        array $details,
        OrderTransactionEntity $orderTransaction
    ): PaymentResponseHandlerResult {
        $request = ['details' => $details];

        try {
            $this->checkoutService->startClient($orderTransaction->getOrder()->getSalesChannelId());
            $response = $this->checkoutService->paymentsDetails($request);
            return $this->paymentResponseHandler->handlePaymentResponse($response, $orderTransaction);
        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());
            throw new PaymentFailedException($exception->getMessage());
        }
    }
}
