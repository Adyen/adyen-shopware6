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

use Adyen\Model\Checkout\PaymentDetailsRequest;
use Adyen\Shopware\Util\DataArrayValidator;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\PaymentDetailsService;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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
    private EntityRepository $orderTransactionRepository;

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
        PaymentResponseHandlerResult $paymentResponseHandlerResult,
        EntityRepository $orderTransactionRepository
    ) {
        $this->paymentResponseService = $paymentResponseService;
        $this->logger = $logger;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->paymentResponseHandlerResult = $paymentResponseHandlerResult;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * @param PaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws PaymentFailedException
     * @throws PaymentCancelledException
     */
    public function processResult(
        PaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ) {
        $orderTransactionId = $transaction->getOrderTransactionId();

        // 1. Load OrderTransaction to get Order ID
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');

        $orderTransaction = $this->orderTransactionRepository
            ->search($criteria, $salesChannelContext->getContext())
            ->get($orderTransactionId);

        // Retrieve paymentResponse and if it exists
        $paymentResponse = $this->paymentResponseService->getWithOrderTransaction($orderTransaction);

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
                    ['orderId' => $orderTransaction->getOrder()->getId()]
                );
                throw new PaymentFailedException($error);
            }

            // Validate the return

            $paymentDetailRequest = new PaymentDetailsRequest(['details'=>$details]);

            $result = $this->paymentDetailsService->getPaymentDetails(
                $paymentDetailRequest,
                $orderTransaction
            );
        }

        // Process the result and handle the transaction
        $this->paymentResponseHandler->handleShopwareAPIs(
            $orderTransaction,
            $salesChannelContext,
            [$result]
        );
    }
}
