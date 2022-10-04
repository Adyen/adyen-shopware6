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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Controller;

use Adyen\AdyenException;
use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\DonationService;
use Adyen\Shopware\Service\PaymentDetailsService;
use Adyen\Shopware\Service\PaymentMethodsBalanceService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaymentStatusService;
use Adyen\Shopware\Service\OrdersService;
use Adyen\Shopware\Service\OrdersCancelService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Order\SalesChannel\SetPaymentOrderRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class StoreApiController
 * @package Adyen\Shopware\Controller
 * @RouteScope(scopes={"store-api"})
 */
class StoreApiController
{
    /**
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;
    /**
     * @var PaymentDetailsService
     */
    private $paymentDetailsService;
    /**
     * @var CheckoutStateDataValidator
     */
    private $checkoutStateDataValidator;
    /**
     * @var PaymentStatusService
     */
    private $paymentStatusService;
    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;
    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;
    /**
     * @var OrderRepository
     */
    private $orderRepository;
    /**
     * @var OrderService
     */
    private $orderService;
    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;
    /**
     * @var OrderTransactionRepository
     */
    private $adyenOrderTransactionRepository;
    /**
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var DonationService
     */
    private $donationService;
    /**
     * @var ConfigurationService
     */
    private $configurationService;
    /**
     * @var PaymentMethodsBalanceService
     */
    private $paymentMethodsBalanceService;
    /**
     * @var OrdersService
     */
    private $ordersService;
    /**
     * @var OrdersService
     */
    private $ordersCancelService;

    /**
     * StoreApiController constructor.
     *
     * @param PaymentMethodsService $paymentMethodsService
     * @param PaymentDetailsService $paymentDetailsService
     * @param DonationService $donationService
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param PaymentStatusService $paymentStatusService
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param PaymentResponseService $paymentResponseService
     * @param OrderRepository $orderRepository
     * @param OrderService $orderService
     * @param StateMachineRegistry $stateMachineRegistry
     * @param LoggerInterface $logger
     * @param EntityRepositoryInterface $orderTransactionRepository
     * @param ConfigurationService $configurationService
     * @param PaymentMethodsBalanceService $paymentMethodsBalanceService
     * @param OrdersService $orderService
     * @param OrdersCancelService $orderCancelService
     */
    public function __construct(
        PaymentMethodsService $paymentMethodsService,
        PaymentDetailsService $paymentDetailsService,
        DonationService $donationService,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        PaymentStatusService $paymentStatusService,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentResponseService $paymentResponseService,
        OrderRepository $orderRepository,
        OrderService $orderService,
        StateMachineRegistry $stateMachineRegistry,
        LoggerInterface $logger,
        EntityRepositoryInterface $orderTransactionRepository,
        ConfigurationService $configurationService,
        OrderTransactionRepository $adyenOrderTransactionRepository,
        PaymentMethodsBalanceService $paymentMethodsBalanceService,
        OrdersService $ordersService,
        OrdersCancelService $ordersCancelService
    ) {
        $this->paymentMethodsService = $paymentMethodsService;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->paymentStatusService = $paymentStatusService;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentResponseService = $paymentResponseService;
        $this->orderRepository = $orderRepository;
        $this->orderService = $orderService;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->logger = $logger;
        $this->donationService = $donationService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->configurationService = $configurationService;
        $this->adyenOrderTransactionRepository = $adyenOrderTransactionRepository;
        $this->paymentMethodsBalanceService = $paymentMethodsBalanceService;
        $this->ordersService = $ordersService;
        $this->ordersCancelService = $ordersCancelService;
    }

    /**
     * @Route(
     *     "/store-api/adyen/payment-methods",
     *     name="store-api.action.adyen.payment-methods",
     *     methods={"GET"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function getPaymentMethods(SalesChannelContext $context): JsonResponse
    {
        return new JsonResponse($this->paymentMethodsService->getPaymentMethods($context));
    }

    /**
     * @Route(
     *     "/store-api/adyen/payment-methods/balance",
     *     name="store-api.action.adyen.payment-methods.balance",
     *     methods={"POST"}
     * )
     *
     * @param SalesChannelContext $context
     * @param Request $request
     * @return JsonResponse
     */
    public function getPaymentMethodsBalance(SalesChannelContext $context, Request $request): JsonResponse
    {
        $number = $request->request->get('number');
        $type = $request->request->get('type');
        $cvc = $request->request->get('cvc');

        return new JsonResponse(
            $this->paymentMethodsBalanceService->getPaymentMethodsBalance($context, $type, $number, $cvc)
        );
    }

    /**
     * @Route(
     *     "/store-api/adyen/orders",
     *     name="store-api.action.adyen.orders",
     *     methods={"POST"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function createOrder(SalesChannelContext $context, Request $request): JsonResponse
    {
        $uuid = Uuid::randomHex();

        return new JsonResponse($this->ordersService->createOrder($context, $uuid));
    }

    /**
     * @Route(
     *     "/store-api/adyen/orders/cancel",
     *     name="store-api.action.adyen.orders.cancel",
     *     methods={"POST"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function cancelOrder(SalesChannelContext $context, Request $request): JsonResponse
    {
        $orderData = $request->request->get('orderData');
        $pspReference = $request->request->get('pspReference');

        return new JsonResponse($this->ordersCancelService->cancelOrder($context, $orderData, $pspReference));
    }

    /**
     * @Route(
     *     "/store-api/adyen/payment-details",
     *     name="store-api.action.adyen.payment-details",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function postPaymentDetails(
        Request $request,
        SalesChannelContext $context
    ): JsonResponse {
        $orderId = $request->request->get('orderId');
        $paymentResponse = $this->paymentResponseService->getWithOrderId($orderId);
        if (!$paymentResponse) {
            $message = 'Could not find a transaction';
            $this->logger->error($message, ['orderId' => $orderId]);
            return new JsonResponse($message, 404);
        }

        // Get state data object if sent
        $stateData = $request->request->get('stateData');

        // Validate stateData object
        if (!empty($stateData)) {
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);
        }

        if (empty($stateData['details'])) {
            $message = 'Details missing in $stateData';
            $this->logger->error(
                $message,
                ['stateData' => $stateData]
            );
            return new JsonResponse($message, 400);
        }

        try {
            $result = $this->paymentDetailsService->getPaymentDetails(
                $stateData,
                $paymentResponse->getOrderTransaction()
            );
        } catch (PaymentFailedException $exception) {
            $message = 'Error occurred finalizing payment';
            $this->logger->error(
                $message,
                ['orderId' => $orderId, 'paymentDetails' => $stateData]
            );
            return new JsonResponse($message, 500);
        }

        // If donation token is present in the result, store it in the custom fields of order transaction.
        $donationToken = $result->getDonationToken();
        if (isset($donationToken) &&
            $this->configurationService->isAdyenGivingEnabled($context->getSalesChannelId())) {
            $storedTransactionCustomFields = $paymentResponse->getOrderTransaction()->getCustomFields() ?: [];
            $transactionCustomFields[PaymentResponseHandler::DONATION_TOKEN] = $donationToken;

            $customFields = array_merge(
                $storedTransactionCustomFields,
                $transactionCustomFields
            );

            $paymentResponse->getOrderTransaction()->setCustomFields($customFields);
            $orderTransactionId = $paymentResponse->getOrderTransactionId();
            $context->getContext()->scope(
                Context::SYSTEM_SCOPE,
                function (Context $context) use ($orderTransactionId, $customFields) {
                    $this->orderTransactionRepository->update([
                        [
                            'id' => $orderTransactionId,
                            'customFields' => $customFields,
                        ]
                    ], $context);
                }
            );
        }

        return new JsonResponse($this->paymentResponseHandler->handleAdyenApis($result));
    }

    /**
     * @Route(
     *     "/store-api/adyen/payment-status",
     *     name="store-api.action.adyen.payment-status",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function getPaymentStatus(Request $request, SalesChannelContext $context): JsonResponse
    {
        $orderId = $request->get('orderId');
        if (empty($orderId)) {
            return new JsonResponse('Order ID not provided', 400);
        }

        try {
            return new JsonResponse(
                $this->paymentStatusService->getWithOrderId($orderId)
            );
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            return new JsonResponse(["isFinal" => true]);
        }
    }

    /**
     * @OA\Post(
     *      path="/adyen/set-payment",
     *      summary="set payment for an order",
     *      operationId="orderSetPayment",
     *      tags={"Store API", "Account"},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(
     *                  property="paymentMethodId",
     *                  description="The ID of the new paymentMethod",
     *                  type="string"
     *              ),
     *              @OA\Property(property="orderId", description="The ID of the order", type="string")
     *          )
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="Successfully set a payment",
     *          @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     )
     * )
     * @Route(
     *     "/store-api/adyen/set-payment",
     *     name="store-api.action.adyen.set-payment",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return SetPaymentOrderRouteResponse
     */
    public function updatePaymentMethod(Request $request, SalesChannelContext $context): SetPaymentOrderRouteResponse
    {
        $this->setPaymentMethod($request->get('paymentMethodId'), $request->get('orderId'), $context);
        return new SetPaymentOrderRouteResponse();
    }

    private function setPaymentMethod(
        string $paymentMethodId,
        string $orderId,
        SalesChannelContext $salesChannelContext
    ): void {
        $context = $salesChannelContext->getContext();
        $initialState = $this->stateMachineRegistry->getInitialState(OrderTransactionStates::STATE_MACHINE, $context);

        /** @var OrderEntity $order */
        $order = $this->orderRepository->getOrder($orderId, $context, ['transactions']);

        $context->scope(
            Context::SYSTEM_SCOPE,
            function () use ($order, $initialState, $orderId, $paymentMethodId, $context): void {
                if ($order->getTransactions() !== null && $order->getTransactions()->count() >= 1) {
                    foreach ($order->getTransactions() as $transaction) {
                        if ($transaction->getStateMachineState()->getTechnicalName()
                            !== OrderTransactionStates::STATE_CANCELLED) {
                            $this->orderService->orderTransactionStateTransition(
                                $transaction->getId(),
                                'cancel',
                                new ParameterBag(),
                                $context
                            );
                        }
                    }
                }
                $transactionAmount = new CalculatedPrice(
                    $order->getPrice()->getTotalPrice(),
                    $order->getPrice()->getTotalPrice(),
                    $order->getPrice()->getCalculatedTaxes(),
                    $order->getPrice()->getTaxRules()
                );

                $this->orderRepository->update($orderId, [
                    'transactions' => [
                        [
                            'id' => Uuid::randomHex(),
                            'paymentMethodId' => $paymentMethodId,
                            'stateId' => $initialState->getId(),
                            'amount' => $transactionAmount,
                        ],
                    ],
                ], $context);
            }
        );
    }

    /**
     * @Route(
     *     "/store-api/adyen/cancel-order-transaction",
     *     name="store-api.action.adyen.cancel-order-transaction",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     * @throws \Adyen\Exception\MissingDataException
     * @throws \JsonException
     */
    public function cancelOrderTransaction(
        Request $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        $context = $salesChannelContext->getContext();
        $orderId = $request->get('orderId');
        $order = $this->orderRepository->getOrder($orderId, $context, ['transactions']);

        $transaction = $order->getTransactions()->filterByState(OrderTransactionStates::STATE_IN_PROGRESS)->first();

        $this->stateMachineRegistry->transition(
            new Transition(OrderTransactionDefinition::ENTITY_NAME, $transaction->getId(), 'cancel', 'stateId'),
            $context
        );

        return new JsonResponse($this->paymentStatusService->getWithOrderId($orderId));
    }

    /**
     * @Route(
     *     "/store-api/adyen/donate",
     *     name="store-api.action.adyen.donate",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     */
    public function donate(
        Request $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        $payload = $request->get('payload');

        $orderId = $payload['orderId'];
        $currency = $payload['amount']['currency'];
        $value = $payload['amount']['value'];
        $returnUrl = $payload['returnUrl'];

        $transaction = $this->adyenOrderTransactionRepository
            ->getFirstAdyenOrderTransactionByStates($orderId, [OrderTransactionStates::STATE_AUTHORIZED]);

        /** @var AbstractPaymentMethodHandler $paymentMethodIdentifier */
        $paymentMethodIdentifier = $transaction->getPaymentMethod()->getHandlerIdentifier();
        $paymentMethodCode = $paymentMethodIdentifier::getPaymentMethodCode();

        $donationToken = $transaction->getCustomFields()['donationToken'];
        $pspReference = $transaction->getCustomFields()['originalPspReference'];

        // Set donation token as null after first call.
        $storedTransactionCustomFields = $transaction->getCustomFields();
        $storedTransactionCustomFields[PaymentResponseHandler::DONATION_TOKEN] = null;

        $orderTransactionId = $transaction->getId();
        $salesChannelContext->getContext()->scope(
            Context::SYSTEM_SCOPE,
            function (Context $salesChannelContext) use ($orderTransactionId, $storedTransactionCustomFields) {
                $this->orderTransactionRepository->update([
                    [
                        'id' => $orderTransactionId,
                        'customFields' => $storedTransactionCustomFields,
                    ]
                ], $salesChannelContext);
            }
        );

        try {
            $this->donationService->donate(
                $salesChannelContext,
                $donationToken,
                $currency,
                $value,
                $returnUrl,
                $pspReference,
                $paymentMethodCode
            );
        } catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
            return new JsonResponse('An unknown error occurred', $e->getCode());
        }

        return new JsonResponse('Donation completed successfully.');
    }
}
