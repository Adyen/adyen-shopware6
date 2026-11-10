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

namespace Adyen\Shopware\Test\Unit;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Environment;
use Adyen\HttpClient\ClientInterface;
use Adyen\Model\Checkout\PaymentResponse;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\PaypalPaymentAttempt\PaypalPaymentAttemptEntity;
use Adyen\Shopware\Exception\PaymentReversedException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Handlers\PaypalPaymentMethodHandler;
use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
use Adyen\Shopware\Models\NotificationProcessingResult;
use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Adyen\Shopware\PaymentMethods\PaypalPaymentMethod;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ExpressCheckoutService;
use Adyen\Shopware\Service\PaymentRequest\PaymentRequestService;
use Adyen\Shopware\Service\PaymentReversalService;
use Adyen\Shopware\Service\PaypalPaymentService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\PaypalPaymentAttemptRepository;
use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Webhook\EventCodes;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use LogicException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRouteResponse;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractHandlePaymentMethodRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Kernel;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

class PaypalPaymentServiceTest extends AdyenTestCase
{
    private MockObject $httpClient;
    private MockObject $cartOrderRoute;
    private MockObject $orderRepository;
    private MockObject $paymentReversalService;
    private MockObject $paypalPaymentAttemptRepository;
    private MockObject $expressCheckoutService;
    private MockObject $cartService;
    private MockObject $logger;
    private MockObject $paymentRequestService;
    private PaypalPaymentService $paypalPaymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->createMock(ClientInterface::class);

        $client = new Client();
        $client->setEnvironment(Environment::TEST);
        $client->setHttpClient($this->httpClient);

        $clientService = $this->getSimpleMock(ClientService::class);
        $clientService->method('getClient')->willReturn($client);

        $this->cartOrderRoute = $this->getSimpleMock(AbstractCartOrderRoute::class);
        $this->orderRepository = $this->getSimpleMock(OrderRepository::class);
        $this->paymentReversalService = $this->getSimpleMock(PaymentReversalService::class);
        $this->paypalPaymentAttemptRepository = $this->getSimpleMock(PaypalPaymentAttemptRepository::class);
        $this->expressCheckoutService = $this->getSimpleMock(ExpressCheckoutService::class);
        $this->cartService = $this->getSimpleMock(CartService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->paymentRequestService = $this->getSimpleMock(PaymentRequestService::class);
        $this->paymentRequestService->method('storeCaptureConfiguration')
            ->willReturn(CaptureConfiguration::automatic());

        // Symfony 5 declares no return type on generate(), so the mock would return null.
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://shop.test/account/order/edit/order-1');

        $this->paypalPaymentService = new PaypalPaymentService(
            $clientService,
            $this->createMock(NumberRangeValueGeneratorInterface::class),
            $this->getSimpleMock(PaymentResponseHandler::class),
            $this->getSimpleMock(SalesChannelRepository::class),
            $this->cartOrderRoute,
            $this->getSimpleMock(AbstractHandlePaymentMethodRoute::class),
            $this->expressCheckoutService,
            $this->cartService,
            $router,
            $this->paymentRequestService,
            $this->orderRepository,
            $this->paymentReversalService,
            $this->paypalPaymentAttemptRepository,
            $this->logger,
            Kernel::SHOPWARE_FALLBACK_VERSION
        );
    }

    public function testOrderCreationFailureReversesThePaymentAndRethrows(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $exception = new LogicException('Product is out of stock');
        $this->cartOrderRoute->method('order')->willThrowException($exception);
        $this->orderRepository->method('getOrderByOrderNumber')->with('10001')->willReturn(null);

        $this->paymentReversalService->expects($this->once())
            ->method('reverse')
            ->with('sales-channel-1', 'PSP123', '10001', $this->anything())
            ->willReturn('REVERSAL1');
        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('saveReversalOutcome')
            ->with('10001', 'REVERSAL1');
        $this->paypalPaymentAttemptRepository->expects($this->never())->method('deleteByMerchantReference');

        try {
            $this->finalize();
            $this->fail('PaymentReversedException was not thrown.');
        } catch (PaymentReversedException $reversedException) {
            $this->assertSame($exception, $reversedException->getPrevious());
        }
    }

    public function testFailedReversalIsRecordedAndOriginalExceptionRethrown(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $this->cartOrderRoute->method('order')->willThrowException(new LogicException('Cart locked'));
        $this->orderRepository->method('getOrderByOrderNumber')->willReturn(null);
        $this->paymentReversalService->method('reverse')->willReturn(null);

        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('saveReversalOutcome')
            ->with('10001', null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cart locked');

        $this->finalize();
    }

    public function testNoReversalWhenTheOrderWasStored(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $this->cartOrderRoute->method('order')->willThrowException(new LogicException('Order stored, not loadable'));
        $this->orderRepository->method('getOrderByOrderNumber')->willReturn($this->createThrowAwayOrder('order-1', 10));

        $this->paymentReversalService->expects($this->never())->method('reverse');
        $this->logger->expects($this->once())->method('error');

        $this->expectException(LogicException::class);

        $this->finalize();
    }

    public function testNoReversalWhenThePaymentHoldsNoFunds(): void
    {
        $this->givenPaymentDetailsResponse('Refused');
        $this->cartOrderRoute->method('order')->willThrowException(new LogicException('Product is out of stock'));

        $this->orderRepository->expects($this->never())->method('getOrderByOrderNumber');
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $this->expectException(LogicException::class);

        $this->finalize();
    }

    public function testOriginalExceptionIsRethrownWhenTheReversalGuardFails(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $exception = new LogicException('Product is out of stock');
        $this->cartOrderRoute->method('order')->willThrowException($exception);
        $this->orderRepository->method('getOrderByOrderNumber')
            ->willThrowException(new LogicException('Database unavailable'));

        $this->paymentReversalService->expects($this->never())->method('reverse');
        $this->logger->expects($this->once())->method('critical');

        $this->expectExceptionObject($exception);

        $this->finalize();
    }

    public function testSuccessfulOrderCreationRemovesTheAttemptAndDoesNotReverse(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $order = $this->createThrowAwayOrder('order-1', 10);
        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $order->setTransactions(new OrderTransactionCollection([$transaction]));
        $this->cartOrderRoute->method('order')->willReturn(new CartOrderRouteResponse($order));

        $this->paymentReversalService->expects($this->never())->method('reverse');
        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('deleteByMerchantReference')
            ->with('10001');

        $this->assertSame('order-1', $this->finalize());
    }

    public function testCaptureConfigurationIsStoredOnTheCreatedOrderTransaction(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $order = $this->createThrowAwayOrder('order-1', 10);
        $order->setTransactions(new OrderTransactionCollection([$transaction]));
        $this->cartOrderRoute->method('order')->willReturn(new CartOrderRouteResponse($order));

        // No attempt with a stored configuration, e.g. created before the update: it is resolved again.
        $this->paymentRequestService->expects($this->once())
            ->method('storeCaptureConfiguration')
            ->with($transaction, PaypalPaymentMethodHandler::class, 'sales-channel-1', null)
            ->willReturn(new CaptureConfiguration(CaptureMode::MANUAL));

        $this->assertSame('order-1', $this->finalize());
    }

    public function testCaptureConfigurationSentWithThePaymentRequestIsStoredOnTheOrderTransaction(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $order = $this->createThrowAwayOrder('order-1', 10);
        $order->setTransactions(new OrderTransactionCollection([$transaction]));
        $this->cartOrderRoute->method('order')->willReturn(new CartOrderRouteResponse($order));
        // Manual when /payments was sent, although the configuration may have changed since.
        $this->givenPaypalPaymentAttempt(
            PaypalPaymentAttemptEntity::STATUS_OPEN,
            (new CaptureConfiguration(CaptureMode::MANUAL))->toArray()
        );

        $this->paymentRequestService->expects($this->once())
            ->method('storeCaptureConfiguration')
            ->with(
                $transaction,
                PaypalPaymentMethodHandler::class,
                'sales-channel-1',
                $this->callback(
                    fn (?CaptureConfiguration $configuration) => $configuration !== null
                        && $configuration->getMode() === CaptureMode::MANUAL
                )
            )
            ->willReturn(new CaptureConfiguration(CaptureMode::MANUAL));

        $this->assertSame('order-1', $this->finalize());
    }

    public function testCaptureConfigurationIsResolvedAgainWhenTheAttemptCannotBeRead(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $order = $this->createThrowAwayOrder('order-1', 10);
        $order->setTransactions(new OrderTransactionCollection([$transaction]));
        $this->cartOrderRoute->method('order')->willReturn(new CartOrderRouteResponse($order));
        $this->paypalPaymentAttemptRepository->method('getByMerchantReference')
            ->willThrowException(new \RuntimeException('Database error'));

        $this->paymentRequestService->expects($this->once())
            ->method('storeCaptureConfiguration')
            ->with($transaction, PaypalPaymentMethodHandler::class, 'sales-channel-1', null)
            ->willReturn(CaptureConfiguration::automatic());

        $this->assertSame('order-1', $this->finalize());
    }

    public function testPaymentRequestSendsAndStoresTheSameCaptureConfiguration(): void
    {
        $captureConfiguration = new CaptureConfiguration(CaptureMode::DELAYED, 3);
        $paymentRequest = new IntegrationPaymentRequest();
        $paymentRequest->setReference('10001');
        $this->paymentRequestService->method('resolvePaypalCaptureConfiguration')
            ->with('sales-channel-1')
            ->willReturn($captureConfiguration);
        $this->paymentRequestService->expects($this->once())
            ->method('buildPaymentRequestFromCart')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                true,
                $this->identicalTo($captureConfiguration)
            )
            ->willReturn($paymentRequest);
        $response = new PaymentResponse();
        $response->setPspReference('PSP123');
        $this->paymentRequestService->method('executePayment')->willReturn($response);

        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('create')
            ->with('10001', 'sales-channel-1', 'PSP123', $captureConfiguration->toArray());

        $this->paypalPaymentService->createPayPalPaymentRequest(
            $this->createCartWithProduct(),
            $this->createSalesChannelContext()
        );
    }

    public function testExpressCheckoutRestoresTheContextWhenFinalizeFails(): void
    {
        $this->httpClient->method('requestHttp')->willThrowException(new AdyenException('Timeout'));

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setName(PaypalPaymentMethod::PAYPAL_PAYMENT_METHOD_NAME);
        // Express checkout from the cart page: the cart token is the session token, so the session context is used
        $oldContext = $this->createSalesChannelContext();
        $oldContext->method('getPaymentMethod')->willReturn($paymentMethod);
        $oldContext->method('getToken')->willReturn('cart-token');

        $customer = new CustomerEntity();
        $customer->setId('customer-1');
        $guestContext = $this->createSalesChannelContext();
        $guestContext->method('getCustomer')->willReturn($customer);

        $this->cartService->method('getCart')->willReturn($this->createCart());
        $this->expressCheckoutService->method('createCustomerAndUpdateContext')->willReturn($guestContext);

        $this->expressCheckoutService->expects($this->never())->method('getSalesChannelContext');
        $this->expressCheckoutService->expects($this->once())
            ->method('changeContext')
            ->with('customer-1', $oldContext);

        $this->expectException(AdyenException::class);

        $this->paypalPaymentService->finalizeExpressPaypalPayment(
            'cart-token',
            $oldContext,
            new Request(),
            new RequestDataBag(),
            ['details' => ['orderID' => 'PAYPAL-ORDER']],
            ['firstName' => 'Test']
        );
    }

    public function testExpressCheckoutUsesTheContextPersistedWithTheCartToken(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $order = $this->createThrowAwayOrder('order-1', 10);
        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        // Express checkout from the product page: the cart token differs from the storefront session token, so the
        // order is placed with the context persisted for the cart token, whatever the session uses
        $sessionContext = $this->createSalesChannelContext();
        $sessionContext->method('getToken')->willReturn('session-token');

        $context = $this->createSalesChannelContext();
        $this->expressCheckoutService->expects($this->once())
            ->method('getSalesChannelContext')
            ->with('cart-token', 'sales-channel-1', $sessionContext)
            ->willReturn($context);

        $cart = $this->createCart();
        $this->cartService->expects($this->once())
            ->method('getCart')
            ->with(...$this->uncachedGetCartArguments('cart-token', $context))
            ->willReturn($cart);

        $this->cartOrderRoute->expects($this->once())
            ->method('order')
            ->with($cart, $context)
            ->willReturn(new CartOrderRouteResponse($order));

        $orderId = $this->paypalPaymentService->finalizeExpressPaypalPayment(
            'cart-token',
            $sessionContext,
            new Request(),
            new RequestDataBag(),
            ['details' => ['orderID' => 'PAYPAL-ORDER']],
            []
        );

        $this->assertSame('order-1', $orderId);
    }

    public function testExpressCheckoutLoadsTheCartWithTheGuestCustomerContext(): void
    {
        $this->givenPaymentDetailsResponse('Authorised');
        $order = $this->createThrowAwayOrder('order-1', 10);
        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-1');
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        // Guest express checkout from the product page: the context persisted with the cart token has no customer
        // and the sales channel default country. The cart has to be loaded with the context that carries the guest
        // customer created from the PayPal address, otherwise its errors (e.g. a shipping method blocked for the
        // default country) are carried over into the order and block it.
        $sessionContext = $this->createSalesChannelContext();
        $sessionContext->method('getToken')->willReturn('session-token');

        $persistedContext = $this->createSalesChannelContext();
        $this->expressCheckoutService->method('getSalesChannelContext')->willReturn($persistedContext);

        $customer = new CustomerEntity();
        $customer->setId('customer-1');
        $guestContext = $this->createSalesChannelContext();
        $guestContext->method('getCustomer')->willReturn($customer);
        $newAddress = ['firstName' => 'Test', 'countryCode' => 'AT'];
        $this->expressCheckoutService->expects($this->once())
            ->method('createCustomerAndUpdateContext')
            ->with($persistedContext, 'cart-token', $newAddress)
            ->willReturn($guestContext);

        $cart = $this->createCart();
        $this->cartService->expects($this->once())
            ->method('getCart')
            ->with(...$this->uncachedGetCartArguments('cart-token', $guestContext))
            ->willReturn($cart);

        $this->cartOrderRoute->expects($this->once())
            ->method('order')
            ->with($cart, $guestContext)
            ->willReturn(new CartOrderRouteResponse($order));

        $orderId = $this->paypalPaymentService->finalizeExpressPaypalPayment(
            'cart-token',
            $sessionContext,
            new Request(),
            new RequestDataBag(),
            ['details' => ['orderID' => 'PAYPAL-ORDER']],
            $newAddress
        );

        $this->assertSame('order-1', $orderId);
    }

    public function testNotificationWithoutOrderIsIgnoredWhenThereIsNoPaypalPaymentAttempt(): void
    {
        $this->paypalPaymentAttemptRepository->method('getByMerchantReference')->willReturn(null);
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $this->assertNull($this->paypalPaymentService->handleNotificationWithoutOrder(
            $this->createNotification(EventCodes::AUTHORISATION, true),
            '10001'
        ));
    }

    public function testSuccessfulAuthorisationWithinGracePeriodIsRescheduled(): void
    {
        $createdAt = new \DateTimeImmutable('-10 minutes');
        $this->givenPaypalPaymentAttempt();
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $result = $this->handleNotificationWithoutOrder(
            $this->createNotification(EventCodes::AUTHORISATION, true, $createdAt)
        );

        $this->assertSame(NotificationProcessingResult::ACTION_RESCHEDULE, $result->getAction());
        $this->assertEquals(
            $createdAt->add(new \DateInterval(PaypalPaymentService::MISSING_ORDER_GRACE_PERIOD)),
            $result->getScheduledProcessingTime()
        );
    }

    public function testSuccessfulAuthorisationAfterGracePeriodReversesThePayment(): void
    {
        $this->givenPaypalPaymentAttempt();

        $this->paymentReversalService->expects($this->once())
            ->method('reverse')
            ->with('sales-channel-1', 'PSP123', '10001', $this->anything())
            ->willReturn('REVERSAL1');
        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('saveReversalOutcome')
            ->with('10001', 'REVERSAL1');

        $result = $this->handleNotificationWithoutOrder($this->createNotification(EventCodes::AUTHORISATION, true));

        $this->assertSame(NotificationProcessingResult::ACTION_DONE, $result->getAction());
        $this->assertNull($result->getError());
    }

    public function testFailedReversalIsRetried(): void
    {
        $this->givenPaypalPaymentAttempt(PaypalPaymentAttemptEntity::STATUS_REVERSAL_FAILED);
        $this->paymentReversalService->method('reverse')->willReturn(null);

        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('saveReversalOutcome')
            ->with('10001', null);

        $result = $this->handleNotificationWithoutOrder($this->createNotification(EventCodes::AUTHORISATION, true));

        $this->assertSame(NotificationProcessingResult::ACTION_RETRY, $result->getAction());
        $this->assertNotNull($result->getError());
    }

    public function testMissingPspReferenceIsNotReversed(): void
    {
        $this->givenPaypalPaymentAttempt();
        $notification = $this->createNotification(EventCodes::AUTHORISATION, true);
        $notification->setPspreference('');

        $this->paymentReversalService->expects($this->never())->method('reverse');
        $this->logger->expects($this->once())->method('critical');

        $result = $this->handleNotificationWithoutOrder($notification);

        $this->assertSame(NotificationProcessingResult::ACTION_DONE, $result->getAction());
        $this->assertNotNull($result->getError());
    }

    public function testAlreadyReversedAttemptIsNotReversedAgain(): void
    {
        $this->givenPaypalPaymentAttempt(PaypalPaymentAttemptEntity::STATUS_REVERSED);
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $result = $this->handleNotificationWithoutOrder($this->createNotification(EventCodes::AUTHORISATION, true));

        $this->assertSame(NotificationProcessingResult::ACTION_DONE, $result->getAction());
    }

    public function testReversalResultNotificationIsNotReversed(): void
    {
        $this->givenPaypalPaymentAttempt(PaypalPaymentAttemptEntity::STATUS_REVERSED);
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $result = $this->handleNotificationWithoutOrder(
            $this->createNotification(EventCodes::CANCEL_OR_REFUND, true)
        );

        $this->assertSame(NotificationProcessingResult::ACTION_DONE, $result->getAction());
    }

    public function testUnsuccessfulAuthorisationIsNotReversed(): void
    {
        $this->givenPaypalPaymentAttempt();
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $result = $this->handleNotificationWithoutOrder($this->createNotification(EventCodes::AUTHORISATION, false));

        $this->assertSame(NotificationProcessingResult::ACTION_DONE, $result->getAction());
    }

    private function handleNotificationWithoutOrder(NotificationEntity $notification): NotificationProcessingResult
    {
        $result = $this->paypalPaymentService->handleNotificationWithoutOrder($notification, '10001');
        $this->assertNotNull($result);

        return $result;
    }

    private function givenPaypalPaymentAttempt(
        string $status = PaypalPaymentAttemptEntity::STATUS_OPEN,
        ?array $captureConfiguration = null
    ): void {
        $attempt = new PaypalPaymentAttemptEntity();
        $attempt->setId('attempt-1');
        $attempt->setMerchantReference('10001');
        $attempt->setSalesChannelId('sales-channel-1');
        $attempt->setStatus($status);
        $attempt->setCaptureConfiguration($captureConfiguration);

        $this->paypalPaymentAttemptRepository->method('getByMerchantReference')
            ->with('10001')
            ->willReturn($attempt);
    }

    private function createNotification(
        string $eventCode,
        bool $success,
        ?\DateTimeInterface $createdAt = null
    ): NotificationEntity {
        $notification = new NotificationEntity();
        $notification->setId('notification-1');
        $notification->setEventCode($eventCode);
        $notification->setSuccess($success);
        $notification->setPspreference('PSP123');
        $notification->setMerchantReference('10001');
        $notification->setErrorCount(0);
        $notification->setCreatedAt($createdAt ?? new \DateTime('-1 hour'));

        return $notification;
    }

    private function givenPaymentDetailsResponse(string $resultCode): void
    {
        $this->httpClient->method('requestHttp')->willReturn([
            'resultCode' => $resultCode,
            'pspReference' => 'PSP123',
            'merchantReference' => '10001',
        ]);
    }

    private function finalize(): string
    {
        return $this->paypalPaymentService->finalizePaypalPayment(
            $this->createSalesChannelContext(),
            $this->createCart(),
            new Request(),
            new RequestDataBag(),
            ['details' => ['orderID' => 'PAYPAL-ORDER']]
        );
    }

    /**
     * Shopware 6.4 creates a cart from a name and a token, 6.5 from the token only.
     */
    private function createCart(): Cart
    {
        $constructor = (new \ReflectionClass(Cart::class))->getConstructor();

        return $constructor->getNumberOfParameters() > 1 ?
            new Cart('adyen', 'cart-token') :
            new Cart('cart-token');
    }

    /**
     * A cart with one product, as the PayPal payment request requires.
     */
    private function createCartWithProduct(): Cart
    {
        $cart = $this->createCart();
        $cart->add(new LineItem('line-item-1', LineItem::PRODUCT_LINE_ITEM_TYPE));
        $cart->setPrice(new CartPrice(
            10.0,
            10.0,
            10.0,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        ));

        return $cart;
    }

    /**
     * Shopware 6.4 takes the cart name before the caching flag of CartService::getCart(), 6.5 the caching flag only.
     * The service is given the version of the installed Shopware, so that the mocked cart service has the matching
     * signature.
     */
    private function uncachedGetCartArguments(string $token, MockObject $context): array
    {
        return str_starts_with(Kernel::SHOPWARE_FALLBACK_VERSION, '6.4') ?
            [$token, $context, CartService::SALES_CHANNEL, false] :
            [$token, $context, false];
    }

    private function createSalesChannelContext(): MockObject
    {
        $context = $this->getSimpleMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-1');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
