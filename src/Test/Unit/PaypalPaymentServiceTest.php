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
use Adyen\Shopware\Exception\PaymentReversedException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
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
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use LogicException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRouteResponse;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractHandlePaymentMethodRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

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

        $this->paypalPaymentService = new PaypalPaymentService(
            $clientService,
            $this->createMock(NumberRangeValueGeneratorInterface::class),
            $this->getSimpleMock(PaymentResponseHandler::class),
            $this->getSimpleMock(SalesChannelRepository::class),
            $this->cartOrderRoute,
            $this->getSimpleMock(AbstractHandlePaymentMethodRoute::class),
            $this->expressCheckoutService,
            $this->cartService,
            $this->getSimpleMock(PaymentRequestService::class),
            $this->orderRepository,
            $this->paymentReversalService,
            $this->paypalPaymentAttemptRepository,
            $this->logger
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
        $order->setTransactions(new OrderTransactionCollection());
        $this->cartOrderRoute->method('order')->willReturn(new CartOrderRouteResponse($order));

        $this->paymentReversalService->expects($this->never())->method('reverse');
        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('deleteByMerchantReference')
            ->with('10001');

        $this->assertSame('order-1', $this->finalize());
    }

    public function testExpressCheckoutRestoresTheContextWhenFinalizeFails(): void
    {
        $this->httpClient->method('requestHttp')->willThrowException(new AdyenException('Timeout'));

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setName(PaypalPaymentMethod::PAYPAL_PAYMENT_METHOD_NAME);
        $oldContext = $this->createSalesChannelContext();
        $oldContext->method('getPaymentMethod')->willReturn($paymentMethod);

        $customer = new CustomerEntity();
        $customer->setId('customer-1');
        $guestContext = $this->createSalesChannelContext();
        $guestContext->method('getCustomer')->willReturn($customer);

        $this->cartService->method('getCart')->willReturn(new Cart('cart-token'));
        $this->expressCheckoutService->method('createCustomerAndUpdateContext')->willReturn($guestContext);

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
            new Cart('cart-token'),
            new Request(),
            new RequestDataBag(),
            ['details' => ['orderID' => 'PAYPAL-ORDER']]
        );
    }

    private function createSalesChannelContext(): MockObject
    {
        $context = $this->getSimpleMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-1');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
