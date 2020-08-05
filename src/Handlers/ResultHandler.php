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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Shopware\Service\PaymentResponseService;
use Psr\Log\LoggerInterface;

class ResultHandler
{
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
     * @var PaymentResponseHandlerResult
     */
    private $paymentResponseHandlerResult;

    /**
     * ResultHandler constructor.
     *
     * @param CheckoutService $checkoutService
     * @param PaymentResponseService $paymentResponseService
     * @param LoggerInterface $logger
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param PaymentDetailsService $paymentDetailsService
     * @param PaymentResponseHandlerResult $paymentResponseHandlerResult
     */
    public function __construct(
        CheckoutService $checkoutService,
        PaymentResponseService $paymentResponseService,
        LoggerInterface $logger,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentDetailsService $paymentDetailsService,
        PaymentResponseHandlerResult $paymentResponseHandlerResult
    ) {
        $this->checkoutService = $checkoutService;
        $this->paymentResponseService = $paymentResponseService;
        $this->logger = $logger;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->paymentResponseHandlerResult = $paymentResponseHandlerResult;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws PaymentException
     */
    public function processResult(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ) {

        // Retrieve paymentResponse and if it is
        $orderId = $transaction->getOrderTransaction()->getOrderId();
        $paymentResponse = $this->paymentResponseService->getWithOrderId($orderId, $salesChannelContext->getToken());
        $orderNumber = $paymentResponse->getOrderNumber();

        $result = $this->paymentResponseHandlerResult->createFromPaymentResponse($paymentResponse);

        if ('RedirectShopper' === $result->getResultCode()) {

            // Validate 3DS1 Post parameters
            // Get MD and PaRes to be validated
            $md = $request->request->get('MD');
            $paRes = $request->request->get('PaRes');

            if (empty($md) || empty($paRes)) {
                throw new PaymentException('MD and/or PaRes parameter is missing from the redirect request');
            }

            // Construct the details object for the paymentDetails request
            $details = [
                'MD' => $md,
                'PaRes' => $paRes
            ];

            // Validate the return
            $result = $this->paymentDetailsService->doPaymentDetails(
                $details,
                $orderNumber,
                $salesChannelContext
            );
        }

        // Process the result and handle the transaction
        $this->paymentResponseHandler->handleShopwareAPIs(
            $transaction,
            $salesChannelContext,
            $result
        );
    }
}
