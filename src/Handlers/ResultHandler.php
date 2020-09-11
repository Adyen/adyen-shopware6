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

use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\PaymentDetailsService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\PaymentResponseService;
use Psr\Log\LoggerInterface;

class ResultHandler
{

    const PA_RES = 'PaRes';
    const MD = 'MD';
    const REDIRECT_RESULT = 'redirectResult';
    const PAYLOAD = 'payload';


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
     * @param PaymentResponseService $paymentResponseService
     * @param LoggerInterface $logger
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param PaymentDetailsService $paymentDetailsService
     * @param PaymentResponseHandlerResult $paymentResponseHandlerResult
     */
    public function __construct(
        PaymentResponseService $paymentResponseService,
        LoggerInterface $logger,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentDetailsService $paymentDetailsService,
        PaymentResponseHandlerResult $paymentResponseHandlerResult
    ) {
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
     * @throws PaymentCancelledException
     * @throws PaymentFailedException
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
            // Validate 3DS1 parameters
            // Get MD and PaRes to be validated
            $md = $request->query->get(self::MD);
            $paRes = $request->query->get(self::PA_RES);
            $redirectResult = $request->query->get(self::REDIRECT_RESULT);
            $payload = $request->query->get(self::PAYLOAD);

            $details = [];

            // Construct the details object for the paymentDetails request
            if (!empty($md)) {
                $details[self::MD] = $md;
            }

            if (!empty($paRes)) {
                $details[self::PA_RES] = $paRes;
            }

            if (!empty($redirectResult)) {
                $details[self::REDIRECT_RESULT] = $redirectResult;
            }

            if (!empty($payload)) {
                $details[self::PAYLOAD] = $payload;
            }

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
