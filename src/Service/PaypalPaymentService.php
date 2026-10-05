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

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Model\Checkout\PaymentDetailsRequest;
use Adyen\Model\Checkout\PaymentDetailsResponse;
use Adyen\Model\Checkout\PaymentResponse;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Exception\PaymentCancelledException;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Exception\PaymentReversedException;
use Adyen\Shopware\Exception\ResolveCountryException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\PaypalPaymentMethodHandler;
use Adyen\Shopware\Models\NotificationProcessingResult;
use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Adyen\Shopware\PaymentMethods\PaypalPaymentMethod;
use Adyen\Shopware\Service\PaymentRequest\PaymentRequestService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\PaypalPaymentAttemptRepository;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Webhook\EventCodes;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\IdStruct;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractHandlePaymentMethodRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * Class PaypalPaymentService.
 *
 * @package Adyen\Shopware\Service
 */
readonly class PaypalPaymentService
{
    /**
     * How long a successful AUTHORISATION waits for its order before a missing order is treated as a failed
     * order creation. Covers a checkout that is still in progress when the webhook arrives.
     */
    public const MISSING_ORDER_GRACE_PERIOD = 'PT30M';

    /**
     * @param ClientService $clientService
     * @param NumberRangeValueGeneratorInterface $numberRangeValueGenerator
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param SalesChannelRepository $salesChannelRepository
     * @param AbstractCartOrderRoute $cartOrderRoute
     * @param AbstractHandlePaymentMethodRoute $handlePaymentMethodRoute
     * @param ExpressCheckoutService $expressCheckoutService
     * @param CartService $cartService
     * @param RouterInterface $router
     * @param PaymentRequestService $paymentRequestService
     * @param OrderRepository $orderRepository
     * @param PaymentReversalService $paymentReversalService
     * @param PaypalPaymentAttemptRepository $paypalPaymentAttemptRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ClientService $clientService,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly PaymentResponseHandler $paymentResponseHandler,
        private readonly SalesChannelRepository $salesChannelRepository,
        private AbstractCartOrderRoute $cartOrderRoute,
        private readonly AbstractHandlePaymentMethodRoute $handlePaymentMethodRoute,
        private readonly ExpressCheckoutService $expressCheckoutService,
        private readonly CartService $cartService,
        private readonly RouterInterface $router,
        private PaymentRequestService $paymentRequestService,
        private OrderRepository $orderRepository,
        private PaymentReversalService $paymentReversalService,
        private PaypalPaymentAttemptRepository $paypalPaymentAttemptRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Finalize PayPal payment and creates order on Shopware.
     *
     * @param SalesChannelContext $context
     * @param Cart $cart
     * @param Request $request
     * @param RequestDataBag $dataBag
     * @param array $stateData
     *
     * @return string Order ID of newly created order
     *
     * @throws AdyenException
     */
    public function finalizePaypalPayment(
        SalesChannelContext $context,
        Cart $cart,
        Request $request,
        RequestDataBag $dataBag,
        array $stateData
    ): string {
        $paymentDetailsResponse = $this->getPaymentApiServiceFromContext($context)
            ->paymentsDetails(new PaymentDetailsRequest($stateData));

        $merchantReference = (string)$paymentDetailsResponse->getMerchantReference();

        try {
            $cart->addExtension(OrderConverter::ORIGINAL_ORDER_NUMBER, new IdStruct($merchantReference));

            $order = $this->cartOrderRoute->order($cart, $context, $dataBag)->getOrder();
        } catch (Throwable $exception) {
            if ($this->reverseOrphanedPayment($context, $paymentDetailsResponse, $exception)) {
                throw new PaymentReversedException($exception);
            }

            throw $exception;
        }

        $this->completePaymentAttempt($merchantReference);

        $request->request->set('orderId', $order->getId());
        $this->handlePaymentMethodRoute->load($request, $context);

        try {
            $this->paymentResponseHandler
                ->handlePaymentResponse($paymentDetailsResponse, $order->getTransactions()->first());

            $returnUrl = $this->router->generate(
                'frontend.account.edit-order.page',
                ['orderId' => $order->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            $paymentTransaction = new AsyncPaymentTransactionStruct(
                $order->getTransactions()->first(),
                $order,
                $returnUrl
            );

            $this->paymentResponseHandler
                ->handleShopwareApis($paymentTransaction, $context, [$paymentDetailsResponse]);
        } catch (PaymentCancelledException $exception) {
            throw PaymentException::customerCanceled(
                $order->getTransactions()->first()->getId(),
                $exception->getMessage()
            );
        } catch (PaymentFailedException $exception) {
            throw PaymentException::asyncFinalizeInterrupted(
                $order->getTransactions()->first()->getId(),
                $exception->getMessage()
            );
        }

        return $order->getId();
    }

    /**
     * Finalize PayPal payment and creates order on Shopware.
     *
     * @param string $cartToken
     * @param SalesChannelContext $context
     * @param Request $request
     * @param RequestDataBag $dataBag
     * @param array $stateData
     * @param array $newAddress
     *
     * @return string Order ID of newly created order
     *
     * @throws AdyenException
     * @throws ResolveCountryException
     * @throws Exception
     */
    public function finalizeExpressPaypalPayment(
        string $cartToken,
        SalesChannelContext $context,
        Request $request,
        RequestDataBag $dataBag,
        array $stateData,
        array $newAddress
    ): string {
        $oldContext = $context;
        $customerID = null;

        if ($context->getPaymentMethod()->getName() !== PaypalPaymentMethod::PAYPAL_PAYMENT_METHOD_NAME) {
            $context = $this->expressCheckoutService->getSalesChannelContext($cartToken, $context->getSalesChannelId());
        }

        $cart = $this->cartService->getCart($cartToken, $context, false);
        if (!empty($newAddress)) {
            $context = $this->expressCheckoutService->createCustomerAndUpdateContext(
                $context,
                $cartToken,
                $newAddress,
            );

            $customerID = $context->getCustomer()->getId();
        }

        try {
            return $this->finalizePaypalPayment($context, $cart, $request, $dataBag, $stateData);
        } finally {
            $customerID && $this->expressCheckoutService->changeContext(
                $customerID,
                $oldContext
            );
        }
    }

    /**
     * @param array $cartData
     * @param SalesChannelContext $context
     * @param SalesChannelContext $updatedContext
     *
     * @param array $stateData
     *
     * @return array
     *
     * @throws AdyenException
     * @throws Exception
     */
    public function createPayPalExpressPaymentRequest(
        array $cartData,
        SalesChannelContext $context,
        SalesChannelContext $updatedContext,
        array $stateData = []
    ): array {
        if ($context->getPaymentMethod()->getName() !== PaypalPaymentMethod::PAYPAL_PAYMENT_METHOD_NAME) {
            $this->expressCheckoutService->changeContext(
                $updatedContext->getCustomerId(),
                $updatedContext
            );
        }

        /** @var Cart $cart */
        $cart = $cartData['cart'];
        $customer = $context->getCustomer();

        if ($customer && !$customer->getGuest()) {
            return $this->createPayPalPaymentRequest($cart, $updatedContext, $stateData);
        }

        $paymentRequest = $this->paymentRequestService->buildPaymentRequestFromCart(
            salesChannelContext: $context,
            cart: $cart,
            returnUrl: $this->salesChannelRepository->getCurrentDomainUrl($context),
            orderReference: $this->generateNextOrderNumberForContext($context),
            paymentMethodCode: PaypalPaymentMethodHandler::getPaymentMethodCode(),
            stateData: $stateData,
            includeShipping: false
        );

        return $this->executePaypalPayment($context, $paymentRequest)->toArray();
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $context
     *
     * @param array $stateData
     *
     * @return array
     *
     * @throws AdyenException
     */
    public function createPayPalPaymentRequest(Cart $cart, SalesChannelContext $context, array $stateData = []): array
    {
        $paymentRequest = $this->paymentRequestService->buildPaymentRequestFromCart(
            salesChannelContext: $context,
            cart: $cart,
            returnUrl: $this->salesChannelRepository->getCurrentDomainUrl($context),
            orderReference: $this->generateNextOrderNumberForContext($context),
            paymentMethodCode: PaypalPaymentMethodHandler::getPaymentMethodCode(),
            stateData: $stateData,
            includeShipping: true
        );

        return $this->executePaypalPayment($context, $paymentRequest)->toArray();
    }

    /**
     * Webhook fallback for a PayPal payment whose Shopware order was never created, e.g. because the finalize
     * request died before the in-process reversal could run. A successful AUTHORISATION waits for the grace
     * period, then reverses the payment if the order is still missing.
     *
     * @param NotificationEntity $notification
     * @param string $merchantReference
     * @param array $logContext
     *
     * @return NotificationProcessingResult|null Null when the merchant reference is not a PayPal payment attempt
     */
    public function handleNotificationWithoutOrder(
        NotificationEntity $notification,
        string $merchantReference,
        array $logContext = []
    ): ?NotificationProcessingResult {
        $paypalPaymentAttempt = $this->paypalPaymentAttemptRepository->getByMerchantReference($merchantReference);
        if (!$paypalPaymentAttempt) {
            return null;
        }

        $logContext['merchantReference'] = $merchantReference;
        $logContext['pspReference'] = $notification->getPspreference();

        if ($paypalPaymentAttempt->isReversed() ||
            $notification->getEventCode() !== EventCodes::AUTHORISATION ||
            !$notification->isSuccess()) {
            // Nothing to compensate, e.g. the reversal result or an AUTHORISATION for an already reversed payment.
            $this->logger->info('Skipped: No order for PayPal payment attempt, no reversal needed.', $logContext + [
                'attemptStatus' => $paypalPaymentAttempt->getStatus(),
            ]);

            return NotificationProcessingResult::done();
        }

        $gracePeriodEnd = $this->getMissingOrderGracePeriodEnd($notification);
        if ($gracePeriodEnd) {
            // The checkout may still be creating the order.
            return NotificationProcessingResult::reschedule($gracePeriodEnd);
        }

        if (empty($notification->getPspreference())) {
            $errorMessage = 'Skipped: Cannot reverse PayPal payment without an order, PSP reference is missing.';
            $this->logger->critical($errorMessage, $logContext);

            return NotificationProcessingResult::done($errorMessage);
        }

        $reversalPspReference = $this->paymentReversalService->reverse(
            $paypalPaymentAttempt->getSalesChannelId(),
            $notification->getPspreference(),
            $merchantReference,
            $logContext + ['trigger' => 'webhook']
        );

        $this->paypalPaymentAttemptRepository->saveReversalOutcome($merchantReference, $reversalPspReference);

        if ($reversalPspReference) {
            return NotificationProcessingResult::done();
        }

        return NotificationProcessingResult::retry('Reversal of PayPal payment without an order failed.');
    }

    /**
     * Sends the PayPal payment to Adyen and records the attempt, because its Shopware order does not exist yet.
     * The record is what lets the webhook fallback reverse the payment if the order is never created.
     *
     * @param SalesChannelContext $context
     * @param IntegrationPaymentRequest $paymentRequest
     *
     * @return PaymentResponse
     *
     * @throws AdyenException
     */
    private function executePaypalPayment(
        SalesChannelContext $context,
        IntegrationPaymentRequest $paymentRequest
    ): PaymentResponse {
        $response = $this->paymentRequestService->executePayment($context, $paymentRequest);

        $this->paypalPaymentAttemptRepository->create(
            (string)$paymentRequest->getReference(),
            $context->getSalesChannelId(),
            $response->getPspReference()
        );

        return $response;
    }

    /**
     * Reverses the payment when the Shopware order could not be created for it. Never throws, so the caller can
     * rethrow the original failure.
     *
     * @param SalesChannelContext $context
     * @param PaymentDetailsResponse $response
     * @param Throwable $exception
     *
     * @return bool Whether the reversal was accepted by Adyen
     */
    private function reverseOrphanedPayment(
        SalesChannelContext $context,
        PaymentDetailsResponse $response,
        Throwable $exception
    ): bool {
        $merchantReference = (string)$response->getMerchantReference();
        $reversed = false;
        $logContext = ['merchantReference' => $merchantReference, 'reason' => $exception->getMessage()];

        try {
            if (empty($response->getPspReference()) || !in_array($response->getResultCode(), [
                PaymentDetailsResponse::RESULT_CODE_AUTHORISED,
                PaymentDetailsResponse::RESULT_CODE_RECEIVED,
                PaymentDetailsResponse::RESULT_CODE_PENDING,
            ], true)) {
                // Nothing is held at Adyen.
                return false;
            }

            if ($this->orderRepository->getOrderByOrderNumber($merchantReference, $context->getContext())) {
                // The order was stored, reversing would leave an order without a payment.
                $this->logger->error(
                    'PayPal order creation failed after the order was stored. Payment is not reversed.',
                    $logContext + ['pspReference' => $response->getPspReference()]
                );

                return false;
            }

            $reversalPspReference = $this->paymentReversalService->reverse(
                $context->getSalesChannelId(),
                $response->getPspReference(),
                $merchantReference,
                $logContext
            );
            $reversed = $reversalPspReference !== null;

            $this->paypalPaymentAttemptRepository->saveReversalOutcome($merchantReference, $reversalPspReference);
        } catch (Throwable $reversalException) {
            // The AUTHORISATION webhook will retry the reversal for the open attempt.
            $this->logger->critical(
                'Could not handle a PayPal payment without a Shopware order.',
                $logContext + [
                    'pspReference' => $response->getPspReference(),
                    'errorMessage' => $reversalException->getMessage(),
                ]
            );
        }

        return $reversed;
    }

    /**
     * The order exists, so the attempt is no longer needed. A failure here must not fail the checkout: a leftover
     * attempt is ignored by the webhook fallback as soon as the order is found.
     *
     * @param string $merchantReference
     *
     * @return void
     */
    private function completePaymentAttempt(string $merchantReference): void
    {
        try {
            $this->paypalPaymentAttemptRepository->deleteByMerchantReference($merchantReference);
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Could not remove the PayPal payment attempt after the order was created.',
                ['merchantReference' => $merchantReference, 'errorMessage' => $exception->getMessage()]
            );
        }
    }

    /**
     * Returns the time until which a missing order is still expected to be created for the notification,
     * or null when the grace period is over.
     *
     * @param NotificationEntity $notification
     *
     * @return \DateTime|null
     */
    private function getMissingOrderGracePeriodEnd(NotificationEntity $notification): ?\DateTime
    {
        $gracePeriodEnd = \DateTime::createFromInterface($notification->getCreatedAt() ?? new \DateTime())
            ->add(new \DateInterval(self::MISSING_ORDER_GRACE_PERIOD));

        return $gracePeriodEnd > new \DateTime() ? $gracePeriodEnd : null;
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function generateNextOrderNumberForContext(SalesChannelContext $context): string
    {
        return $this->numberRangeValueGenerator->getValue(
            OrderDefinition::ENTITY_NAME,
            $context->getContext(),
            $context->getSalesChannel()->getId()
        );
    }

    /**
     * @param SalesChannelContext $context
     *
     * @return PaymentsApi
     *
     * @throws AdyenException
     */
    private function getPaymentApiServiceFromContext(SalesChannelContext $context): PaymentsApi
    {
        return new PaymentsApi(
            $this->clientService->getClient($context->getSalesChannelId())
        );
    }
}
