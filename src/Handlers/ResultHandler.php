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

use Adyen\Service\Validator\DataArrayValidator;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\PaymentMethods\PaymentMethods;
use Adyen\Shopware\Service\PaymentDetailsService;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;

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
        EntityRepositoryInterface $paymentMethodRepository,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentDetailsService $paymentDetailsService,
        PaymentResponseHandlerResult $paymentResponseHandlerResult
    ) {
        $this->paymentResponseService = $paymentResponseService;
        $this->logger = $logger;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->paymentResponseHandlerResult = $paymentResponseHandlerResult;
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
        $orderTransaction = $transaction->getOrderTransaction();
        // Retrieve paymentResponse and if it exists
        $paymentResponse = $this->paymentResponseService->getWithOrderTransaction($orderTransaction);

        if (!$paymentResponse) {
            $error = 'Payment response not found.';
            $this->logger->error(
                $error,
                ['orderNumber' => $transaction->getOrder()->getOrderNumber()]
            );
            throw new PaymentFailedException($error);
        }

        $result = $this->paymentResponseHandlerResult->createFromPaymentResponse($paymentResponse);

        if ('RedirectShopper' === $result->getResultCode()) {
            $requestResponse = $request->getMethod() === 'GET' ? $request->query->all() : $request->request->all();

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
                    ['orderNumber' => $transaction->getOrder()->getOrderNumber()]
                );
                throw new PaymentFailedException($error);
            }

            // Validate the return
            $paymentDetails = $this->paymentDetailsService->getPaymentDetails(
                ['details' => $details],
                $transaction->getOrder()->getSalesChannelId()
            );

            $additionalData = $paymentDetails['additionalData'] ?? [];
            $partialPayments = $this->getPartialPaymentsFromPaymentDetails($additionalData);
            if (!empty($partialPayments)) {
                $this->handlePartialPayment($orderTransaction, $transaction->getOrder(), $partialPayments, $paymentDetails, $salesChannelContext);
                return;
            }

            $result = $this->paymentResponseHandler
                ->handlePaymentResponse($paymentDetails, $orderTransaction);

        }

        // Process the result and handle the transaction
        $this->paymentResponseHandler->handleShopwareAPIs(
            $transaction->getOrderTransaction(),
            $salesChannelContext,
            $result,
            $transaction->getOrder()->getOrderNumber()
        );
    }

    /**
     * @param OrderTransactionEntity $originalOrderTransaction
     * @param array $partialPayments
     * @param $paymentDetails
     * @param SalesChannelContext $salesChannelContext
     * @throws PaymentCancelledException
     * @throws PaymentFailedException
     */
    public function handlePartialPayment(
        OrderTransactionEntity $originalOrderTransaction,
        OrderEntity $order,
        array $partialPayments,
        $paymentDetails,
        SalesChannelContext $salesChannelContext
    ): void {
        /** @var AbstractPaymentMethodHandler $selectedPaymentHandler */
        $selectedPaymentHandler = $originalOrderTransaction->getPaymentMethod()->getHandlerIdentifier();
        $selectedPaymentMethod = $selectedPaymentHandler::$isGiftCard
            ? $selectedPaymentHandler::getBrand()
            : $selectedPaymentHandler::getPaymentMethodCode();

        foreach ($partialPayments as $payment) {
            if ($payment['paymentMethod'] === $selectedPaymentMethod) {
                // Update the amount on the original transaction to only the part paid by the selected method
                $originalOrderTransaction->setAmount(new CalculatedPrice(
                    floatval($payment['paymentAmount']),
                    floatval($payment['paymentAmount']),
                    $order->getPrice()->getCalculatedTaxes(), // @todo need to recalculate taxes
                    $order->getPrice()->getTaxRules()
                ));
                $result = $this->paymentResponseHandler->handlePaymentResponse(
                    array_merge($paymentDetails, ['pspReference' => $payment['pspReference']]),
                    $originalOrderTransaction
                );
                $this->paymentResponseHandler->handleShopwareApis(
                    $originalOrderTransaction,
                    $salesChannelContext,
                    $result,
                    $order->getOrderNumber()
                );
            } else {
                // Fetch payment method entity for the other payment method (if supported)
                $paymentMethodEntity = $this->paymentMethodRepository->search(
                    (new Criteria())->addFilter(new EqualsFilter(
                        'handlerIdentifier',
                        PaymentMethods::getPaymentMethodHandlerByCode($payment['paymentMethod'])
                    )),
                    $salesChannelContext->getContext()
                )->first();

                if ($paymentMethodEntity instanceof PaymentMethodEntity) {
                    // clone original transaction and overwrite the payment method ID and paid amount
                    $overwrites = [
                        'paymentMethodId' => $paymentMethodEntity->getId()
                    ];
                    $newOrderTransaction = $this->paymentResponseHandler->cloneOrderTransaction(
                        $salesChannelContext->getContext(),
                        $originalOrderTransaction,
                        $order->getPrice(),
                        floatval($payment['paymentAmount']),
                        $overwrites
                    );
                    $result = $this->paymentResponseHandler->handlePaymentResponse(
                        array_merge($paymentDetails, ['pspReference' => $payment['pspReference']]),
                        $newOrderTransaction
                    );

                    $this->paymentResponseHandler->handleShopwareApis(
                        $newOrderTransaction,
                        $salesChannelContext,
                        $result,
                        $order->getOrderNumber()
                    );
                } else {

                    // @fixme what happens if the other payment method is not yet supported by the plugin?
                    // use original payment method and set a custom parameter on it's handler that we can display later
                }
            }
        }
    }


    /**
     * retrieve partial payments from additional data.
     * e.g [
     *       'order-1-paymentMethod' => 'genericgiftcard',
     *       'order-1-pspReference' => '1234567890123456',
     *       'order-1-paymentAmount' => 'EUR 60.00',
     *       'order-2-paymentMethod' => 'ideal',
     *       'order-2-pspReference' => '1234567890123456',
     *       'order-2-paymentAmount' => 'EUR 40.00',
     *    ]
     * @param $additionalData
     * @return array
     */
    private function getPartialPaymentsFromPaymentDetails($additionalData): array
    {
        $partialPayments = [];
        foreach ($additionalData as $key => $value) {
            if (1 === preg_match('/^(order-)(?P<index>\d+)(-)(?P<field>\w+)$/', $key, $matches)) {
                // remove currency string from amount
                if ($matches['field'] === 'paymentAmount') {
                    $value = preg_replace("/[^\d.]/", "", $value);
                }

                if (isset($partialPayments[$matches['index']])) {
                    $partialPayments[$matches['index']][$matches['field']] = $value;
                } else {
                    $partialPayments[$matches['index']] = [$matches['field'] => $value];
                }
            }
        }

        return $partialPayments;
    }
}
