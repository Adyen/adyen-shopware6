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
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandlerResult;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

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
     * @var CheckoutStateDataValidator
     */
    private $checkoutStateDataValidator;

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
     * @param string $orderNumber
     * @param SalesChannelContext $context
     * @return array
     */
    public function doPaymentDetails(
        array $details,
        string $orderNumber,
        SalesChannelContext $context
    ): PaymentResponseHandlerResult {
        // Validate if the payment is not paid yet
        if (false /* TODO is transaction paid */) {
            $this->logger->warning(
                'paymentDetails is called for an already paid order. Sales channel Api context token: ' .
                $context->getToken()
            );
        }

        // Get paymentData for the paymentDetails request
        $paymentResponse = $this->paymentResponseService->getWithSalesChannelApiContextTokenAndOrderNumber(
            $context->getToken(),
            $orderNumber
        );

        // Check if the payment response is not empty and contains the paymentData
        if (empty($paymentResponse)) {
            $this->logger->error('paymentResponse is empty. Sales channel Api context token: ' . $context->getToken());
            //TODO return error
        }

        $paymentResponse = json_decode($paymentResponse->getResponse(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            //TODO error handling
        }

        if (empty($paymentResponse['paymentData'])) {
            $this->logger->error(
                'paymentData is missing from the paymentResponse. Sales channel Api context token: ' .
                $context->getToken()
            );
            //TODO return error
        }

        // Construct paymentDetails request object
        $request = [
            'paymentData' => $paymentResponse['paymentData'],
            'details' => $details
        ];

        try {
            return $this->paymentResponseHandler->handlePaymentResponse(
                $this->checkoutService->paymentsDetails($request),
                $orderNumber,
                $context
            );
        } catch (AdyenException $exception) {
            // TODO error handling
            $this->logger->error($exception->getMessage());
        }
    }
}
