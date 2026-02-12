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

use Adyen\Shopware\Exception\PaymentException;
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
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

abstract class AbstractPaymentMethodHandler extends AbstractPaymentHandler
{
    const string SHOPPER_INTERACTION_CONTAUTH = 'ContAuth';
    const string SHOPPER_INTERACTION_ECOMMERCE = 'Ecommerce';

    const array ALLOWED_LINE_ITEM_TYPES = [
        'product',
        'option-values',
        'customized-products-option'
    ];

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
     * @var array $paymentResults
     */
    protected array $paymentResults = [];

    /**
     * @var AbstractSalesChannelContextFactory $salesChannelContextFactory
     */
    protected AbstractSalesChannelContextFactory $salesChannelContextFactory;

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
     * @var array $orderRequestData
     */
    private array $orderRequestData = [];

    /**
     * @var int|null $remainingAmount
     */
    private ?int $remainingAmount = null;

    /**
     * @var EntityRepository $orderRepository
     */
    private EntityRepository $orderRepository;

    /**
     * @var EntityRepository $orderTransactionRepository
     */
    private EntityRepository $orderTransactionRepository;

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
     * @param AbstractSalesChannelContextFactory $salesChannelContextFactory
     * @param EntityRepository $orderTransactionRepository
     * @param EntityRepository $orderRepository
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
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        EntityRepository $orderTransactionRepository,
        EntityRepository $orderRepository,
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
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->paymentRequestService = $paymentRequestService;
    }

    /**
     * @param PaymentHandlerType $type
     * @param string $paymentMethodId
     * @param Context $context
     *
     * @return bool
     */
    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
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
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @param Struct|null $validateStruct
     *
     * @return RedirectResponse
     *
     * @throws AdyenException
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): RedirectResponse {
        [$orderTransaction, $order, $countryStateId, $customerGroupId] =
            $this->loadOrderTransactionAndOrder($transaction, $context);

        $currentRequest = $this->requestStack->getCurrentRequest();
        $contextToken = $currentRequest->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN);

        // 3. Build SalesChannelContext
        $salesChannelContext = $this->salesChannelContextFactory->create(
            $contextToken,
            $order->getSalesChannelId(),
            [
                SalesChannelContextService::CURRENCY_ID => $order->getCurrencyId(),
                SalesChannelContextService::LANGUAGE_ID => $order->getLanguageId(),
                SalesChannelContextService::CUSTOMER_ID => $order->getOrderCustomer()->getCustomerId(),
                SalesChannelContextService::COUNTRY_STATE_ID => $countryStateId,
                SalesChannelContextService::CUSTOMER_GROUP_ID => $customerGroupId,
                SalesChannelContextService::PERMISSIONS => OrderConverter::ADMIN_EDIT_ORDER_PERMISSIONS,
                SalesChannelContextService::VERSION_ID => $context->getVersionId(),
            ]
        );

        $this->paymentsApiService = new PaymentsApi(
            $this->clientService->getClient($salesChannelContext->getSalesChannelId())
        );

        $countStateData = 0;
        $requestStateData = $currentRequest->get('stateData');
        if ($requestStateData) {
            $requestStateData = json_decode($requestStateData, true);
            $countStateData++;
        }
        $countStoredStateData = $this->paymentStateDataService->countStoredStateData($salesChannelContext);
        $countStateData += $countStoredStateData;
        //If condition to check more than 1 PM
        if ($countStateData > 1) {
            $adyenOrderResponse = $this->createAdyenOrder($salesChannelContext, $order);
            $this->handleAdyenOrderPayment(
                $transaction,
                $adyenOrderResponse,
                $salesChannelContext,
                $order,
                $orderTransaction
            );
        }

        $transactionId = $transaction->getOrderTransactionId();
        $storedStateData = $this->paymentStateDataService->getStoredStateData($salesChannelContext, $transactionId);

        /*
         * For single gift card payments, $storedStateData will be used.
         * For all other cases, $requestStateData can be used or $stateData can be null.
         */
        $stateData = $requestStateData ?? $storedStateData ?? [];

        $billieData = [];
        $companyName = $currentRequest->get('companyName');
        $registrationNumber = $currentRequest->get('registrationNumber');

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
            $adyenRequest = $this->getAdyenPaymentRequest(
                $salesChannelContext,
                $orderTransaction,
                $transaction,
                $order,
                $stateData,
                $this->remainingAmount,
                $this->orderRequestData,
                $billieData
            );
            //make /payments call
            $this->paymentsCall($salesChannelContext, $adyenRequest, $orderTransaction);
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

        $orderNumber = $order->getOrderNumber();

        if (empty($orderNumber)) {
            $message = 'Order number is missing';
            throw PaymentException::asyncProcessInterrupted($transactionId, $message);
        }

        try {
            $this->paymentResponseHandler
                ->handleShopwareApis($orderTransaction, $salesChannelContext, $this->paymentResults);
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
            return new RedirectResponse($this->getReturnUrl($transaction, $order));
        }

        return new RedirectResponse($transaction->getReturnUrl());
    }

    /**
     * @param PaymentTransactionStruct $transaction
     * @param $adyenOrderResponse
     * @param SalesChannelContext $salesChannelContext
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return void
     *
     * @throws PaymentException
     */
    public function handleAdyenOrderPayment(
        PaymentTransactionStruct $transaction,
        $adyenOrderResponse,
        SalesChannelContext $salesChannelContext,
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction
    ): void {
        if (empty($adyenOrderResponse)) {
            return;
        }
        $transactionId = $transaction->getOrderTransactionId();

        //New Multi-Gift-card implementation
        $remainingOrderAmount = $this->currency->sanitize(
            $order->getPrice()->getTotalPrice(),
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

            $giftcardPaymentRequest = $this->getAdyenPaymentRequest(
                $salesChannelContext,
                $orderTransaction,
                $transaction,
                $order,
                $storedStateData,
                $partialAmount,
                $this->orderRequestData
            );

            //make /payments call
            $this->paymentsCall($salesChannelContext, $giftcardPaymentRequest, $orderTransaction);

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
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     *
     * @return void
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        [$orderTransaction, $order, $countryStateId, $customerGroupId] =
            $this->loadOrderTransactionAndOrder($transaction, $context);

        // 3. Build SalesChannelContext
        $salesChannelContext = $this->salesChannelContextFactory->create(
            $order->getDeepLinkCode() ?? $orderTransaction->getOrderId(),
            $order->getSalesChannelId(),
            [
                SalesChannelContextService::CURRENCY_ID => $order->getCurrencyId(),
                SalesChannelContextService::LANGUAGE_ID => $order->getLanguageId(),
                SalesChannelContextService::CUSTOMER_ID => $order->getOrderCustomer()->getCustomerId(),
                SalesChannelContextService::COUNTRY_STATE_ID => $countryStateId,
                SalesChannelContextService::CUSTOMER_GROUP_ID => $customerGroupId,
                SalesChannelContextService::PERMISSIONS => OrderConverter::ADMIN_EDIT_ORDER_PERMISSIONS,
                SalesChannelContextService::VERSION_ID => $context->getVersionId(),
            ]
        );

        try {
            $this->resultHandler->processResult($transaction, $request, $salesChannelContext);
        } catch (PaymentCancelledException $exception) {
            throw PaymentException::customerCanceled($transaction->getOrderTransactionId(), $exception->getMessage());
        } catch (PaymentFailedException $exception) {
            throw PaymentException::asyncFinalizeInterrupted(
                $transaction->getOrderTransactionId(),
                $exception->getMessage()
            );
        }
    }

    /**
     * @param $salesChannelContext
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param $transaction
     * @param OrderEntity $order
     * @param $stateData
     * @param $partialAmount
     * @param $orderRequestData
     * @param array $billieData
     *
     * @return IntegrationPaymentRequest
     *
     * @throws PaymentException
     */
    protected function getAdyenPaymentRequest(
        $salesChannelContext,
        OrderTransactionEntity $orderTransactionEntity,
        $transaction,
        OrderEntity $order,
        $stateData,
        $partialAmount,
        $orderRequestData,
        array $billieData = []
    ): IntegrationPaymentRequest {
        $transactionId = $orderTransactionEntity->getId();

        if (!empty($billieData)) {
            $stateData['billieData'] = $billieData;
        }

        try {
            $returnUrl = in_array($stateData['paymentMethod']['type'] ?? '', ['bcmc_mobile', 'twint'])
                ? $this->getReturnUrl($transaction, $order)
                : $transaction->getReturnUrl();

            return $this->paymentRequestService->buildPaymentRequestFromOrder(
                salesChannelContext: $salesChannelContext,
                orderEntity: $order,
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
                $order->getOrderNumber(),
                $exception->getMessage()
            );
            $this->logger->error($message);
            throw PaymentException::asyncProcessInterrupted($transactionId, $message);
        }
    }

    /**
     * Load OrderTransaction and Order with related data.
     *
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     *
     * @return array{OrderTransactionEntity, OrderEntity, string|null, string|null}
     */
    protected function loadOrderTransactionAndOrder(PaymentTransactionStruct $transaction, Context $context): array
    {
        $orderTransactionId = $transaction->getOrderTransactionId();

        $orderTransaction = $this->orderTransactionRepository
            ->search(new Criteria([$orderTransactionId]), $context)
            ->get($orderTransactionId);

        if (!$orderTransaction) {
            throw new \RuntimeException("OrderTransaction not found.");
        }

        $orderId = $orderTransaction->getOrderId();

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('language');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('customer');
        $criteria->addAssociation('lineItems');

        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order) {
            throw new \RuntimeException("Order not found.");
        }

        $billingAddress = $order->getBillingAddress();
        $countryStateId = $billingAddress?->getCountryStateId();

        $customer = $order->getOrderCustomer()?->getCustomer();
        $customerGroupId = $customer?->getGroupId();

        return [$orderTransaction, $order, $countryStateId, $customerGroupId];
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param IntegrationPaymentRequest $request
     * @param OrderTransactionEntity $transaction
     *
     * @return void
     */
    private function paymentsCall(
        SalesChannelContext $salesChannelContext,
        IntegrationPaymentRequest $request,
        OrderTransactionEntity $transaction
    ): void {
        $transactionId = $transaction->getId();

        try {
            $response = $this->paymentRequestService->executePayment(
                $salesChannelContext,
                $request
            );
        } catch (AdyenException $exception) {
            $message = sprintf(
                "There was an error with the /payments request. Order number %s: %s",
                $transaction->getOrder()?->getOrderNumber(),
                $exception->getMessage()
            );
            $this->logger->error($message);
            throw PaymentException::asyncProcessInterrupted($transactionId, $message);
        }

        $this->paymentResults[] = $this->paymentResponseHandler
            ->handlePaymentResponse($response, $transaction, false);
    }

    /**
     * @param PaymentTransactionStruct $transaction
     * @param OrderEntity $orderEntity
     *
     * @return string
     */
    private function getReturnUrl(PaymentTransactionStruct $transaction, OrderEntity $orderEntity): string
    {
        $query = parse_url($transaction->getReturnUrl(), PHP_URL_QUERY);
        parse_str($query, $params);
        $token = $params['_sw_payment_token'] ?? '';

        return $this->router->generate(
            'payment.adyen.proxy-finalize-transaction',
            [
                '_sw_payment_token' => $token,
                'orderId' => $orderEntity->getId(),
                'transactionId' => $transaction->getOrderTransactionId()
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param OrderEntity $order
     *
     * @return array
     */
    private function createAdyenOrder(SalesChannelContext $salesChannelContext, OrderEntity $order): array
    {
        $uuid = Uuid::randomHex();
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amount = $this->currency->sanitize(
            $order->getPrice()->getTotalPrice(),
            $salesChannelContext->getCurrency()->getIsoCode()
        );
        return $this->ordersService->createOrder($salesChannelContext, $uuid, $amount, $currency);
    }
}
