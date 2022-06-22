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
     * @var ClientService
     */
    private $clientService;

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
     * @param ClientService $clientService
     * @param PaymentResponseHandler $paymentResponseHandler
     */
    public function __construct(
        LoggerInterface $logger,
        ClientService $clientService,
        PaymentResponseHandler $paymentResponseHandler
    ) {
        $this->logger = $logger;
        $this->clientService = $clientService;
        $this->paymentResponseHandler = $paymentResponseHandler;
    }

    /**
     * @param array $requestData
     * @param string $salesChannelId
     * @param string|null $orderTransactionId
     * @param string|null $paymentReference
     * @return PaymentResponseHandlerResult
     * @throws PaymentFailedException
     * @throws \Adyen\Shopware\Exception\ValidationException
     */
    public function getPaymentDetails(
        array $requestData,
        string $salesChannelId,
        string $orderTransactionId = null,
        string $paymentReference = null
    ): PaymentResponseHandlerResult {

        try {
            $checkoutService = new CheckoutService(
                $this->clientService->getClient($salesChannelId)
            );
            $response = $checkoutService->paymentsDetails($requestData);
            return $this->paymentResponseHandler
                ->handlePaymentResponse($response, $orderTransactionId, $paymentReference);
        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());
            throw new PaymentFailedException($exception->getMessage());
        }
    }
}
