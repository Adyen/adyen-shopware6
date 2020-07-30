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
 * Adyen Payment Module
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Handlers;

use Adyen\Shopware\Exception\PaymentException;
use Adyen\Shopware\Service\PaymentDetailsService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Shopware\Service\PaymentResponseService;
use Psr\Log\LoggerInterface;

class ResultHandler
{
    /**
     * @var Request
     */
    private $request;

    /**
     * @var CheckoutService
     */
    private $checkoutService;

    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;

    /**
     * @var PaymentDetailsService
     */
    private $paymentDetailsService;

    /**
     * ResultHandler constructor.
     *
     * @param Request $request
     * @param CheckoutService $checkoutService
     * @param PaymentResponseService $paymentResponseService
     * @param LoggerInterface $logger
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param PaymentDetailsService $paymentDetailsService
     */
    public function __construct(
        Request $request,
        CheckoutService $checkoutService,
        PaymentResponseService $paymentResponseService,
        LoggerInterface $logger,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentDetailsService $paymentDetailsService
    ) {
        $this->request = $request;
        $this->checkoutService = $checkoutService;
        $this->paymentResponseService = $paymentResponseService;
        $this->logger = $logger;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentDetailsService = $paymentDetailsService;
    }

    /**
     * @return RedirectResponse
     * @throws InconsistentCriteriaIdsException
     */
    public function processResult(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ) {
        // Get details to validate
        $details = $request->request->all();
        if (empty($details)) {
            throw new AsyncPaymentFinalizeException('Query parameters are missing from return URL');
        }

        // Get order number
        $orderNumber = $request->get(PaymentResponseHandler::ADYEN_MERCHANT_REFERENCE);
        if (empty($orderNumber)) {
            throw new AsyncPaymentFinalizeException('Adyen merchant reference parameter is missing from return URL');
        }

        // Validate the return
        $result = $this->paymentDetailsService->doPaymentDetails(
            $details,
            $orderNumber,
            $salesChannelContext
        );

        try {
            // Process the result and handle the transaction
            $this->paymentResponseHandler->handleShopwareAPIs(
                $transaction,
                $salesChannelContext,
                $result
            );
        } catch (PaymentException $exception) {
            throw new AsyncPaymentFinalizeException($exception->getMessage());
        }

    }
}
