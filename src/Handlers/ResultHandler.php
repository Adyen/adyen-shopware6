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
use Adyen\Shopware\Exception\PaymentDetailsCallFailedException;
use Adyen\Shopware\Exception\PaymentException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\PaymentDetailsService;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
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
     * Custom field set on an order transaction when the /payments/details call failed on the shopper's return.
     * Shopware then fails the transaction, although the payment outcome is unknown, so a successful
     * AUTHORISATION webhook may still settle it.
     */
    const PAYMENT_DETAILS_CALL_FAILED = 'adyenPaymentDetailsCallFailed';


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
     * @var OrderTransactionRepository
     */
    private $orderTransactionRepository;

    /**
     * ResultHandler constructor.
     *
     * @param PaymentResponseService $paymentResponseService
     * @param LoggerInterface $logger
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param PaymentDetailsService $paymentDetailsService
     * @param PaymentResponseHandlerResult $paymentResponseHandlerResult
     * @param OrderTransactionRepository $orderTransactionRepository
     */
    public function __construct(
        PaymentResponseService $paymentResponseService,
        LoggerInterface $logger,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentDetailsService $paymentDetailsService,
        PaymentResponseHandlerResult $paymentResponseHandlerResult,
        OrderTransactionRepository $orderTransactionRepository
    ) {
        $this->paymentResponseService = $paymentResponseService;
        $this->logger = $logger;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->paymentResponseHandlerResult = $paymentResponseHandlerResult;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws PaymentFailedException
     * @throws PaymentCancelledException
     */
    public function processResult(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ) {
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

            $paymentDetailRequest = new PaymentDetailsRequest(['details'=>$details]);

            try {
                $result = $this->paymentDetailsService->getPaymentDetails(
                    $paymentDetailRequest,
                    $transaction->getOrderTransaction()
                );
            } catch (PaymentDetailsCallFailedException $exception) {
                $this->markPaymentDetailsCallFailed($transaction->getOrderTransaction());

                throw $exception;
            }
        }

        // Process the result and handle the transaction
        $this->paymentResponseHandler->handleShopwareAPIs(
            $transaction,
            $salesChannelContext,
            [$result]
        );
    }

    /**
     * A failure to store the marker must not replace the payment exception thrown to the shopper.
     *
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return void
     */
    private function markPaymentDetailsCallFailed(OrderTransactionEntity $orderTransaction): void
    {
        $orderTransaction->setCustomFields(array_merge(
            $orderTransaction->getCustomFields() ?? [],
            [self::PAYMENT_DETAILS_CALL_FAILED => true]
        ));

        try {
            $this->orderTransactionRepository->updateCustomFields($orderTransaction);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Unable to mark the order transaction after the /payments/details call failed.',
                ['orderTransactionId' => $orderTransaction->getId(), 'errorMessage' => $exception->getMessage()]
            );
        }
    }
}
