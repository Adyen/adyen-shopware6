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

use Adyen\Model\Checkout\PaymentCompletionDetails;
use Adyen\Model\Checkout\PaymentDetailsRequest;
use Adyen\Shopware\Util\DataArrayValidator;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentException;
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
    private PaymentResponseService $paymentResponseService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var PaymentResponseHandler
     */
    private PaymentResponseHandler $paymentResponseHandler;

    /**
     * @var PaymentDetailsService
     */
    private PaymentDetailsService $paymentDetailsService;

    /**
     * @var PaymentResponseHandlerResult
     */
    private PaymentResponseHandlerResult $paymentResponseHandlerResult;

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
     *
     * @throws PaymentFailedException
     * @throws PaymentCancelledException
     */
    public function processResult(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        // Retrieve paymentResponse and if it exists
        $paymentResponse = $this->paymentResponseService->getWithOrderTransaction($transaction->getOrderTransaction());

        if (!$paymentResponse) {
            throw new PaymentFailedException('Payment response not found.');
        }

        $result = $this->paymentResponseHandlerResult->createFromPaymentResponse($paymentResponse);
        $requestResponse = $request->getMethod() === 'GET' ? $request->query->all() : $request->request->all();

        if ('RedirectShopper' === $result->getResultCode() ||
            (
                $salesChannelContext->getPaymentMethod()->getFormattedHandlerIdentifier() ===
                'handler_adyen_bancontactmobilepaymentmethodhandler' &&
                !empty($requestResponse['redirectResult'] ?? null)
            )
        ) {
            $details = DataArrayValidator::getArrayOnlyWithApprovedKeys($requestResponse, [
                self::PA_RES,
                self::MD,
                self::REDIRECT_RESULT,
                self::PAYLOAD,
            ]);

            if (empty($details)) {
                $error = 'Payment details are missing.';
                $this->logger->error(
                    $error,
                    ['orderId' => $transaction->getOrder()->getId()]
                );
                throw new PaymentFailedException($error);
            }

            // Validate the return

            $paymentDetailRequest = new PaymentDetailsRequest(['details' => $details]);

            $result = $this->paymentDetailsService->getPaymentDetails(
                $paymentDetailRequest,
                $transaction->getOrderTransaction()
            );
        }

        // Process the result and handle the transaction
        $this->paymentResponseHandler->handleShopwareAPIs(
            $transaction,
            $salesChannelContext,
            [$result]
        );
    }
}
