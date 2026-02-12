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

use Adyen\AdyenException;
use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Shopware\PaymentMethods\RatepayDirectdebitPaymentMethod;
use Adyen\Shopware\PaymentMethods\RatepayPaymentMethod;
use Adyen\Shopware\Service\PaymentRequest\PaymentRequestService;
use Adyen\Shopware\Util\CheckoutStateDataValidator;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\OrdersService;
use Adyen\Shopware\Service\PaymentStateDataService;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Util\Currency;
use Adyen\Shopware\Util\RatePayDeviceFingerprintParamsProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

abstract class AbstractPaymentMethodHandler implements AsynchronousPaymentHandlerInterface
{
    const SHOPPER_INTERACTION_CONTAUTH = 'ContAuth';
    const SHOPPER_INTERACTION_ECOMMERCE = 'Ecommerce';

    const ALLOWED_LINE_ITEM_TYPES = [
        'product',
        'option-values',
        'customized-products-option'
    ];
    /**
     * Error codes that are safe to display to the shopper.
     *
     * @see https://docs.adyen.com/development-resources/error-codes
     */
    const SAFE_ERROR_CODES = ['124'];

    public static bool $isOpenInvoice = false;
    public static bool $supportsManualCapture = false;
    public static bool $supportsPartialCapture = false;

    /**
     * @var ClientService
     */
    protected ClientService $clientService;

    /**
     * @var Currency
     */
    protected Currency $currency;

    /**
     * @var ConfigurationService
     */
    protected ConfigurationService $configurationService;

    /**
     * @var CheckoutStateDataValidator
     */
    protected CheckoutStateDataValidator $checkoutStateDataValidator;

    /**
     * @var RatePayDeviceFingerprintParamsProvider
     */
    protected RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider;

    /**
     * @var PaymentStateDataService
     */
    protected PaymentStateDataService $paymentStateDataService;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var SalesChannelRepository
     */
    protected SalesChannelRepository $salesChannelRepository;

    /**
     * @var PaymentResponseHandler
     */
    protected PaymentResponseHandler $paymentResponseHandler;

    /**
     * @var ResultHandler
     */
    protected ResultHandler $resultHandler;

    /**
     * @var OrderTransactionStateHandler
     */
    protected OrderTransactionStateHandler $orderTransactionStateHandler;

    /**
     * @var RouterInterface
     */
    protected RouterInterface $router;

    /**
     * @var EntityRepository
     */
    protected EntityRepository $currencyRepository;

    /**
     * @var EntityRepository
     */
    protected EntityRepository $productRepository;

    /**
     * @var RequestStack
     */
    protected RequestStack $requestStack;

    /**
     * @var AbstractContextSwitchRoute
     */
    private AbstractContextSwitchRoute $contextSwitchRoute;

    /**
     * @var OrdersService
     */
    private OrdersService $ordersService;

    /**
     * @var PaymentsApi $paymentsApiService
     */
    private PaymentsApi $paymentsApiService;

    /**
     * @var array $paymentResults
     */
    private array $paymentResults = [];

    /**
     * @var array $orderRequestData
     */
    private array $orderRequestData = [];

    /**
     * @var int|null $remainingAmount
     */
    private ?int $remainingAmount = null;

    /**
     * @var PaymentRequestService $paymentRequestService
     */
    private PaymentRequestService $paymentRequestService;

    /**
     * AbstractPaymentMethodHandler constructor.
     *
     * @param OrdersService $ordersService
     * @param ConfigurationService $configurationService
     * @param ClientService $clientService
     * @param Currency $currency
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider
     * @param PaymentStateDataService $paymentStateDataService
     * @param SalesChannelRepository $salesChannelRepository
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param ResultHandler $resultHandler
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param RouterInterface $router
     * @param RequestStack $requestStack
     * @param EntityRepository $currencyRepository
     * @param EntityRepository $productRepository
     * @param AbstractContextSwitchRoute $contextSwitchRoute
     * @param LoggerInterface $logger
     * @param PaymentRequestService $paymentRequestService
     */
    public function __construct(
        OrdersService $ordersService,
        ConfigurationService $configurationService,
        ClientService $clientService,
        Currency $currency,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        RatePayDeviceFingerprintParamsProvider $ratePayFingerprintParamsProvider,
        PaymentStateDataService $paymentStateDataService,
        SalesChannelRepository $salesChannelRepository,
        PaymentResponseHandler $paymentResponseHandler,
        ResultHandler $resultHandler,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        RouterInterface $router,
        RequestStack $requestStack,
        EntityRepository $currencyRepository,
        EntityRepository $productRepository,
        AbstractContextSwitchRoute $contextSwitchRoute,
        LoggerInterface $logger,
        PaymentRequestService $paymentRequestService
    ) {
        $this->ordersService = $ordersService;
        $this->clientService = $clientService;
        $this->currency = $currency;
        $this->configurationService = $configurationService;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->ratePayFingerprintParamsProvider = $ratePayFingerprintParamsProvider;
        $this->paymentStateDataService = $paymentStateDataService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->resultHandler = $resultHandler;
        $this->logger = $logger;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->router = $router;
        $this->requestStack = $requestStack;
        $this->currencyRepository = $currencyRepository;
        $this->productRepository = $productRepository;
        $this->contextSwitchRoute = $contextSwitchRoute;
        $this->paymentRequestService = $paymentRequestService;
    }

    /**
     * @return string
     */
    abstract public static function getPaymentMethodCode(): string;

    /**
     * @return string|null
     */
    public static function getBrand(): ?string
    {
        return null;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     * @throws AdyenException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {

        $this->paymentsApiService = new PaymentsApi(
            $this->clientService->getClient($salesChannelContext->getSalesChannel()->getId())
        );

        $countStateData = 0;
        $requestStateData = $dataBag->get('stateData');
        if ($requestStateData) {
            $requestStateData = json_decode($requestStateData, true);
            $countStateData++;
        }
        $countStoredStateData = $this->paymentStateDataService->countStoredStateData($salesChannelContext);
        $countStateData += $countStoredStateData;
        //If condition to check more than 1 PM
        if ($countStateData > 1 || ($countStateData === 1 && static::getPaymentMethodCode() !== 'giftcard')) {
            $adyenOrderResponse = $this->createAdyenOrder($salesChannelContext, $transaction);
            $this->handleAdyenOrderPayment($transaction, $adyenOrderResponse, $salesChannelContext);
        }

        $transactionId = $transaction->getOrderTransaction()->getId();
        $storedStateData = $this->paymentStateDataService->getStoredStateData($salesChannelContext, $transactionId);

        /*
         * For single gift card payments, $storedStateData will be used.
         * For all other cases, $requestStateData can be used or $stateData can be null.
         */
        $stateData = $requestStateData ?? $storedStateData ?? [];

        $billieData = [];
        $companyName = $dataBag->get('companyName');
        $registrationNumber = $dataBag->get('registrationNumber');

        if ($companyName) {
            $billieData['companyName'] = $companyName;
        }

        if ($registrationNumber) {
            $billieData['registrationNumber'] = $registrationNumber;
        }

        /*
         * If there are more than one stateData and /payments calls have been completed,
         * check the remaining order amount for final /payments call.
         *
         * remainingAmount is only set if there are multiple payments.
         */
        if (is_null($this->remainingAmount) || $this->remainingAmount > 0) {
            $request = $this->getAdyenPaymentRequest(
                $salesChannelContext,
                $transaction,
                $stateData,
                $this->remainingAmount,
                $this->orderRequestData,
                $billieData
            );
            //make /payments call
            $this->paymentsCall($salesChannelContext, $request, $transaction);
            //Remove all state data if stored or from giftcard
            if ($storedStateData) {
                $this->paymentStateDataService->deletePaymentStateDataFromId($storedStateData['id']);
            }

            $paymentMethodType = array_key_exists('paymentMethod', $stateData) ?
                $stateData['paymentMethod']['type'] : '';
            if ($paymentMethodType === RatepayPaymentMethod::RATEPAY_PAYMENT_METHOD_TYPE ||
                $paymentMethodType === RatepayDirectdebitPaymentMethod::RATEPAY_DIRECTDEBIT_PAYMENT_METHOD_TYPE
            ) {
                $this->ratePayFingerprintParamsProvider->clear();
            }
        }

        $orderNumber = $transaction->getOrder()->getOrderNumber();

        if (empty($orderNumber)) {
            $message = 'Order number is missing';
            throw PaymentException::asyncProcessInterrupted($transactionId, $message);
        }

        try {
            $this->paymentResponseHandler
                ->handleShopwareApis($transaction, $salesChannelContext, $this->paymentResults);
        } catch (PaymentCancelledException $exception) {
            throw PaymentException::customerCanceled($transactionId, $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw PaymentException::asyncFinalizeInterrupted($transactionId, $exception->getMessage());
        }

        /*
         * Removes the giftcard payment method from the databag
         *  if the previous order was completed with a giftcard.
         */
        $this->contextSwitchRoute->switchContext(
            new RequestDataBag(
                [
                    SalesChannelContextService::PAYMENT_METHOD_ID => null
                ]
            ),
            $salesChannelContext
        );

        // Payment had no error, continue the process

        // If Bancontact mobile payment is used, redirect to proxy finalize transaction endpoint
        if (array_key_exists('paymentMethod', $stateData) &&
            in_array($stateData['paymentMethod']['type'], ['bcmc_mobile', 'twint'])) {
            return new RedirectResponse($this->getReturnUrl($transaction));
        }

        return new RedirectResponse($transaction->getReturnUrl());
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        try {
            $this->resultHandler->processResult($transaction, $request, $salesChannelContext);
        } catch (PaymentCancelledException $exception) {
            throw PaymentException::customerCanceled($transactionId, $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw PaymentException::asyncFinalizeInterrupted($transactionId, $exception->getMessage());
        }
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param $adyenOrderResponse
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    public function handleAdyenOrderPayment(
        AsyncPaymentTransactionStruct $transaction,
        $adyenOrderResponse,
        SalesChannelContext $salesChannelContext
    ): void {
        if (empty($adyenOrderResponse)) {
            return;
        }
        $transactionId = $transaction->getOrderTransaction()->getId();

        //New Multi-Gift-card implementation
        $remainingOrderAmount = $this->currency->sanitize(
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        );

        $this->orderRequestData = [
            'orderData' => $adyenOrderResponse['orderData'],
            'pspReference' => $adyenOrderResponse['pspReference']
        ];

        $stateData = $this->paymentStateDataService->fetchRedeemedGiftCardsFromContextToken(
            $salesChannelContext->getToken()
        );

        foreach ($stateData->getElements() as $statedataArray) {
            $storedStateData = json_decode($statedataArray->getStateData(), true);
            $giftcardValue = $this->currency->sanitize(
                $storedStateData['giftcard']['value'],
                $salesChannelContext->getCurrency()->getIsoCode()
            );
            $partialAmount = min($remainingOrderAmount, $giftcardValue); //convert to integer from float

            $giftcardPaymentRequest = $this->getPaymentRequest(
                $salesChannelContext,
                $transaction,
                $storedStateData,
                $partialAmount,
                $this->orderRequestData
            );

            //make /payments call
            $this->paymentsCall($salesChannelContext, $giftcardPaymentRequest, $transaction);

            $remainingOrderAmount -= $partialAmount;

            // Remove the used state.data
            $this->paymentStateDataService->deletePaymentStateDataFromId($statedataArray->getId());
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'Invalid payment state data.'
            );
        }

        $this->remainingAmount = $remainingOrderAmount;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction
     * @param array $stateData
     * @param int|null $partialAmount
     * @param mixed[] $orderRequestData
     * @param mixed[] $billieData
     *
     * @return IntegrationPaymentRequest
     */
    protected function getAdyenPaymentRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction,
        array $stateData,
        ?int $partialAmount,
        array $orderRequestData,
        array $billieData = []
    ): IntegrationPaymentRequest {
        if (!empty($billieData)) {
            $stateData['billieData'] = $billieData;
        }

        try {
            $returnUrl = in_array($stateData['paymentMethod']['type'] ?? '', ['bcmc_mobile', 'twint'])
                ? $this->getReturnUrl($transaction)
                : $transaction->getReturnUrl();

            return $this->paymentRequestService->buildPaymentRequestFromOrder(
                salesChannelContext: $salesChannelContext,
                orderEntity: $transaction->getOrder(),
                returnUrl: $returnUrl,
                paymentMethodCode: static::getPaymentMethodCode(),
                stateData: $stateData,
                partialAmount: $partialAmount,
                adyenOrderData: $orderRequestData,
                isOpenInvoice: static::$isOpenInvoice,
                shopperInteraction: static::class === OneClickPaymentMethodHandler::class
                    ? PaymentRequestService::SHOPPER_INTERACTION_CONTAUTH
                    : PaymentRequestService::SHOPPER_INTERACTION_ECOMMERCE
            );
        } catch (PaymentException $exception) {
            $this->logger->error($exception->getMessage());

            throw $exception;
        } catch (\Exception $exception) {
            $message = sprintf(
                "There was an error with the payment method. Order number: %s Missing data: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );
            $this->logger->error($message);
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrder()?->getTransactions()?->first()?->getId(),
                $message
            );
        }
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param IntegrationPaymentRequest $request
     * @param AsyncPaymentTransactionStruct $transaction
     *
     * @return void
     */
    private function paymentsCall(
        SalesChannelContext $salesChannelContext,
        IntegrationPaymentRequest $request,
        AsyncPaymentTransactionStruct $transaction
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        try {
            $response = $this->paymentRequestService->executePayment(
                $salesChannelContext,
                $request
            );
        } catch (AdyenException $exception) {
            $message = sprintf(
                "There was an error with the /payments request. Order number %s: %s",
                $transaction->getOrder()->getOrderNumber(),
                $exception->getMessage()
            );
            $this->displaySafeErrorMessages($exception);
            $this->logger->error($message);
            throw PaymentException::asyncProcessInterrupted($transactionId, $message);
        }
        $this->paymentResults[] = $this->paymentResponseHandler
            ->handlePaymentResponse($response, $transaction->getOrderTransaction(), false);
    }

    /**
     * @param AdyenException $exception
     *
     * @return void
     */
    private function displaySafeErrorMessages(AdyenException $exception): void
    {
        if ('validation' === $exception->getErrorType()
            && in_array($exception->getAdyenErrorCode(), self::SAFE_ERROR_CODES)) {
            $this->requestStack->getSession()->getFlashBag()->add('warning', $exception->getMessage());
        }
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     *
     * @return string
     */
    private function getReturnUrl(AsyncPaymentTransactionStruct $transaction): string
    {
        $query = parse_url($transaction->getReturnUrl(), PHP_URL_QUERY);
        parse_str($query, $params);
        $token = $params['_sw_payment_token'] ?? '';

        return $this->router->generate(
            'payment.adyen.proxy-finalize-transaction',
            [
                '_sw_payment_token' => $token,
                'orderId' => $transaction->getOrder()->getId(),
                'transactionId' => $transaction->getOrderTransaction()->getId()
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param $transaction
     *
     * @return array
     */
    private function createAdyenOrder(SalesChannelContext $salesChannelContext, $transaction): array
    {
        $uuid = Uuid::randomHex();
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize(
            $transaction->getOrder()->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        );
        return $this->ordersService->createOrder($salesChannelContext, $uuid, $amount, $currency);
    }
}
