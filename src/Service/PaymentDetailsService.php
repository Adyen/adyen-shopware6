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
use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandlerResult;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class PaymentDetailsService
{
    /**
     * @var CheckoutService
     */
    private $checkoutService;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var SalesChannelRepository
     */
    private $salesChannelRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;

    /**
     * PaymentDetailsService constructor.
     *
     * @param SalesChannelRepository $salesChannelRepository
     * @param LoggerInterface $logger
     * @param CheckoutService $checkoutService
     * @param ConfigurationService $configurationService
     * @param PaymentResponseService $paymentResponseService
     * @param PaymentResponseHandler $paymentResponseHandler
     */
    public function __construct(
        SalesChannelRepository $salesChannelRepository,
        LoggerInterface $logger,
        CheckoutService $checkoutService,
        ConfigurationService $configurationService,
        PaymentResponseService $paymentResponseService,
        PaymentResponseHandler $paymentResponseHandler
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->logger = $logger;
        $this->checkoutService = $checkoutService;
        $this->configurationService = $configurationService;
        $this->paymentResponseService = $paymentResponseService;
        $this->paymentResponseHandler = $paymentResponseHandler;
    }

    /**
     * @param array $details
     * @param $orderTransaction
     * @return PaymentResponseHandlerResult
     * @throws PaymentFailedException
     */
    public function doPaymentDetails(
        array $details,
        OrderTransactionEntity $orderTransaction
    ): PaymentResponseHandlerResult {
        // Get paymentData for the paymentDetails request
        $paymentResponse = $this->paymentResponseService->getWithOrderTransaction($orderTransaction);
        return $this->doPaymentDetailsWithPaymentResponse($details, $paymentResponse);
    }

    /**
     * @param array $details
     * @param PaymentResponseEntity|null $paymentResponse
     * @return PaymentResponseHandlerResult
     * @throws PaymentFailedException
     */
    public function doPaymentDetailsWithPaymentResponse(
        array $details,
        PaymentResponseEntity $paymentResponse
    ): PaymentResponseHandlerResult {
        // Check if the payment response is not empty and contains the paymentData
        if (empty($paymentResponse)) {
            $errorMessage = 'paymentResponse is empty.';
            $this->logger->error(
                $errorMessage,
                ['payment details array' => $details]
            );
            throw new PaymentFailedException($errorMessage);
        }

        $orderTransaction = $paymentResponse->getOrderTransaction();
        $paymentResponseArray = json_decode($paymentResponse->getResponse(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = 'Payment response is corrupt.';
            $this->logger->error(
                $errorMessage,
                [
                    'paymentResponseArray' => $paymentResponseArray,
                    'orderTransaction' => $orderTransaction
                ]
            );
            throw new PaymentFailedException($errorMessage);
        }

        if (empty($paymentResponseArray['paymentData'])) {
            $errorMessage = 'paymentData is missing from the paymentResponse.';
            $this->logger->error(
                $errorMessage,
                [
                    'paymentResponseArray' => $paymentResponseArray,
                    'orderTransaction' => $orderTransaction
                ]
            );
            throw new PaymentFailedException($errorMessage);
        }

        // Construct paymentDetails request object
        $request = [
            'paymentData' => $paymentResponseArray['paymentData'],
            'details' => $details
        ];

        try {
            $this->checkoutService->startClient($orderTransaction->get('order')->getSalesChannelId());
            $response = $this->checkoutService->paymentsDetails($request);
            return $this->paymentResponseHandler->handlePaymentResponse($response, $orderTransaction);
        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());
            throw new PaymentFailedException($exception->getMessage());
        }
    }
}
