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
use Adyen\Shopware\Exception\ValidationException;
use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\DonationService;
use Adyen\Shopware\Service\PaymentDetailsService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\PaymentRequestService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaymentStatusService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
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
     * @var PaymentRequestService
     */
    private $paymentRequestService;
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
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var CartService
     */
    private $cartService;
    /**
     * @var CartCalculator
     */
    private $cartCalculator;
    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * @var DonationService
     */
    private $donationService;
    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * StoreApiController constructor.
     *
     * @param CartService $cartService
     * @param CartCalculator $cartCalculator
     * @param ClientService $clientService
     * @param PaymentMethodsService $paymentMethodsService
     * @param PaymentDetailsService $paymentDetailsService
     * @param PaymentRequestService $paymentRequestService
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
     */
    public function __construct(
        CartService $cartService,
        CartCalculator $cartCalculator,
        ClientService $clientService,
        PaymentMethodsService $paymentMethodsService,
        PaymentDetailsService $paymentDetailsService,
        PaymentRequestService $paymentRequestService,
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
        ConfigurationService $configurationService
    ) {
        $this->cartService = $cartService;
        $this->cartCalculator = $cartCalculator;
        $this->paymentMethodsService = $paymentMethodsService;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->paymentRequestService = $paymentRequestService;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->paymentStatusService = $paymentStatusService;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentResponseService = $paymentResponseService;
        $this->orderRepository = $orderRepository;
        $this->orderService = $orderService;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->logger = $logger;
        $this->clientService = $clientService;
        $this->donationService = $donationService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->configurationService = $configurationService;
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
     *     "/store-api/adyen/payments",
     *     name="store-api.action.adyen.payments",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     * @throws \Adyen\AdyenException
     */
    public function makePayment(Request $request, SalesChannelContext $context): JsonResponse
    {
        $returnUrl = $request->get('returnUrl');
        $stateData = $request->get('stateData');
        $data = json_decode($stateData, true);
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $calculatedCart = $this->cartCalculator->calculate($cart, $context);
        $totalPrice = $calculatedCart->getPrice()->getTotalPrice();
        $paymentMethod = $context->getPaymentMethod();
        $paymentHandler = $paymentMethod->getHandlerIdentifier();
        $reference = Uuid::fromStringToHex($calculatedCart->getToken());
        $currency = $this->paymentRequestService->getCurrency($context->getCurrencyId(), $context->getContext());
        $lineItems = $this->paymentRequestService->getLineItems(
            $calculatedCart->getLineItems(),
            $context,
            $currency,
            $calculatedCart->getPrice()->getTaxStatus()
        );

        $request = $this->paymentRequestService->buildPaymentRequest(
            $data,
            $context,
            $paymentHandler,
            $totalPrice,
            $reference,
            $returnUrl,
            $lineItems
        );

        $checkoutService = new CheckoutService(
            $this->clientService->getClient($context->getSalesChannel()->getId())
        );

        try {
            $response = $checkoutService->payments($request);
        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());

            return new JsonResponse('An error occurred.', 400);
        }

        $result = $this->paymentResponseHandler->handlePaymentResponse($response, null, $reference);

        return new JsonResponse($this->paymentResponseHandler->handleAdyenApis($result));
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
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData((array)$stateData);
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
                $context->getSalesChannelId(),
                $paymentResponse->getOrderTransactionId()
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
        if (!is_null($donationToken) && $this->configurationService->isAdyenGivingEnabled($context->getSalesChannelId())) {
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
     *     "/store-api/adyen/prepared-payment-details",
     *     name="store-api.action.adyen.prepared-payment-details",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     * @throws ValidationException
     */
    public function getPreparedPaymentDetails(
        Request $request,
        SalesChannelContext $context
    ): JsonResponse {

        $paymentReference = $request->request->get('paymentReference');
        $paymentResponse = $this->paymentResponseService->getWithPaymentReference($paymentReference);
        if (!$paymentResponse) {
            $message = 'Could not find a transaction';
            $this->logger->error($message, ['paymentReference' => $paymentReference]);
            return new JsonResponse($message, 404);
        }

        // Get state data object if sent
        $stateData = $request->request->get('stateData');

        // Validate stateData object
        if (!empty($stateData)) {
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData((array)$stateData);
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
                $context->getSalesChannelId(),
                null,
                $paymentResponse->getPaymentReference()
            );
        } catch (PaymentFailedException $exception) {
            $message = 'Error occurred finalizing payment';
            $this->logger->error(
                $message,
                ['paymentReference' => $paymentReference, 'paymentDetails' => $stateData]
            );
            return new JsonResponse($message, 500);
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
     * @Route(
     *     "/store-api/adyen/prepared-payment-status",
     *     name="store-api.action.adyen.prepared-payment-status",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     * @throws \Adyen\Exception\MissingDataException
     * @throws \JsonException
     */
    public function getPreparedPaymentStatus(Request $request, SalesChannelContext $context): JsonResponse
    {
        $paymentReference = $request->get('paymentReference');
        if (empty($paymentReference)) {
            return new JsonResponse('Payment reference not provided', 400);
        }

        return new JsonResponse(
            $this->paymentStatusService->getWithPaymentReference($paymentReference)
        );
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
     * @param SalesChannelContext $context
     * @return JsonResponse
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

        $order = $this->orderRepository->getOrder($orderId, $salesChannelContext->getContext(), ['transactions', 'currency']);
        $transaction = $order->getTransactions()->filterByState(OrderTransactionStates::STATE_AUTHORIZED)->first();
        $donationToken = $transaction->getCustomFields()['donationToken'];
        $pspReference = $transaction->getCustomFields()['originalPspReference'];

        // Set donation token as null after first call.
        $storedTransactionCustomFields = $transaction->getCustomFields();
        $storedTransactionCustomFields[PaymentResponseHandler::DONATION_TOKEN] = null;
        $transaction->setCustomFields($storedTransactionCustomFields);
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
            $this->donationService->donate($salesChannelContext, $donationToken, $currency, $value, $returnUrl, $pspReference);
        }
        catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
            return new JsonResponse('An unknown error occurred', $e->getCode());
        }

        return new JsonResponse('Donation completed successfully.');
    }
}
