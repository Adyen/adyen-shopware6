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
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param PaymentResponseService $paymentResponseService
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
     */
    public function doPaymentDetailsWithPaymentResponse(
        array $details,
        PaymentResponseEntity $paymentResponse
    ): PaymentResponseHandlerResult {
// Check if the payment response is not empty and contains the paymentData
        if (empty($paymentResponse)) {
            $this->logger->error(
                'paymentResponse is empty.',
                ['payment details array' => $details]
            );
            //TODO return error
        }

        $orderTransaction = $paymentResponse->getOrderTransaction();
        $paymentResponseArray = json_decode($paymentResponse->getResponse(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error(
                'Payment response is corrupt.',
                [
                    'paymentResponseArray' => $paymentResponseArray,
                    'orderTransaction' => $orderTransaction
                ]
            );
            //TODO throw exception
        }

        if (empty($paymentResponseArray['paymentData'])) {
            $this->logger->error(
                'paymentData is missing from the paymentResponse.',
                [
                    'paymentResponseArray' => $paymentResponseArray,
                    'orderTransaction' => $orderTransaction
                ]
            );
            //TODO return error
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
            // TODO error handling
            $this->logger->error($exception->getMessage());
        }
    }
}
