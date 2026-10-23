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

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\Notification\NotificationEntityCollection;
use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;
use Adyen\Shopware\ScheduledTask\ProcessNotificationsHandler;
use Adyen\Shopware\ScheduledTask\Webhook\WebhookHandlerFactory;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaypalPaymentService;
use Adyen\Shopware\Service\PluginPaymentMethodsService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * A successful AUTHORISATION must be applied to a transaction that Shopware already set to failed
 * (e.g. /payments/details threw on the shopper's return), as it is for a transaction in progress.
 */
class AuthorisationAfterFailedTransactionTest extends AdyenTestCase
{
    private MockObject $stateHandler;
    private MockObject $notificationService;
    private ?bool $done = null;
    private array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);
        $this->notificationService = $this->getSimpleMock(NotificationService::class);
        $this->notificationService->method('changeNotificationState')
            ->willReturnCallback(function (string $id, string $property, bool $state) {
                if ($property === 'done') {
                    $this->done = $state;
                }
            });
        $this->notificationService->method('saveError')
            ->willReturnCallback(function (string $id, string $message) {
                $this->errors[] = $message;
            });
    }

    public function testSuccessfulAuthorisationPaysAnInProgressTransaction(): void
    {
        $this->stateHandler->expects($this->never())->method('process');
        $this->stateHandler->expects($this->once())->method('paid')->with('tx-1');

        $this->runWithTransactionIn('in_progress', true, false);

        $this->assertTrue($this->done);
    }

    public function testSuccessfulAuthorisationPaysAFailedTransaction(): void
    {
        $this->stateHandler->expects($this->never())->method('process');
        $this->stateHandler->expects($this->once())->method('paid')->with('tx-1');

        $this->runWithTransactionIn('failed', true, false);

        $this->assertTrue($this->done);
    }

    public function testSuccessfulAuthorisationWithManualCaptureAuthorisesAFailedTransaction(): void
    {
        $calls = [];
        $this->stateHandler->expects($this->once())->method('process')->with('tx-1')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'process';
            });
        $this->stateHandler->expects($this->once())->method('authorize')->with('tx-1')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'authorize';
            });
        $this->stateHandler->expects($this->never())->method('paid');

        $this->runWithTransactionIn('failed', true, true);

        $this->assertSame(['process', 'authorize'], $calls);
        $this->assertTrue($this->done);
    }

    public function testFailedAuthorisationLeavesAFailedTransactionUnchanged(): void
    {
        $this->stateHandler->expects($this->never())->method('process');
        $this->stateHandler->expects($this->never())->method('paid');
        $this->stateHandler->expects($this->never())->method('authorize');
        $this->stateHandler->expects($this->never())->method('fail');

        $this->runWithTransactionIn('failed', false, false);

        $this->assertTrue($this->done);
    }

    private function runWithTransactionIn(string $technicalName, bool $success, bool $manualCapture): void
    {
        $notification = new NotificationEntity();
        $notification->setId('notification-1');
        $notification->setEventCode('AUTHORISATION');
        $notification->setSuccess($success);
        $notification->setPspreference('PSP_A');
        $notification->setMerchantReference('10001');
        $notification->setPaymentMethod('ideal');
        $notification->setAmountValue('1000');
        $notification->setAmountCurrency('EUR');
        $notification->setAdditionalData('{}');
        $notification->setErrorCount(0);
        $this->notificationService->method('getScheduledUnprocessedNotifications')
            ->willReturn(new NotificationEntityCollection([$notification]));

        $currency = new CurrencyEntity();
        $currency->setId('currency-eur');
        $currency->setIsoCode('EUR');

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setOrderNumber('10001');
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $state = new StateMachineStateEntity();
        $state->setId('state-1');
        $state->setTechnicalName($technicalName);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('payment-method-1');
        $paymentMethod->setHandlerIdentifier('Adyen\Shopware\Handlers\IdealPaymentMethodHandler');

        $transaction = new OrderTransactionEntity();
        $transaction->setId('tx-1');
        $transaction->setStateMachineState($state);
        $transaction->setAmount(
            new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection())
        );
        $transaction->setOrder($order);
        $transaction->setPaymentMethod($paymentMethod);
        $transaction->setCustomFields(['originalPspReference' => 'PSP_A']);

        $orderRepository = $this->getSimpleMock(OrderRepository::class);
        $orderRepository->method('getOrderByOrderNumber')->willReturn($order);

        $paymentResponse = $this->getSimpleMock(PaymentResponseEntity::class);
        $paymentResponse->method('getOrderTransactionId')->willReturn('tx-1');
        $paymentResponseService = $this->getSimpleMock(PaymentResponseService::class);
        $paymentResponseService->method('getWithPspreference')->with('PSP_A')->willReturn($paymentResponse);

        $transactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $transactionRepository->method('getWithId')->with('tx-1')->willReturn($transaction);

        $captureService = $this->getSimpleMock(CaptureService::class);
        $captureService->method('isManualCaptureActive')->willReturn($manualCapture);
        $captureService->method('isCaptureOnShipmentEnabled')->willReturn(false);
        $captureService->method('isManualCapture')->willReturn($manualCapture);
        $captureService->method('requiresCaptureOnShipment')->willReturn(false);

        $pluginPaymentMethods = $this->getSimpleMock(PluginPaymentMethodsService::class);
        $pluginPaymentMethods->method('getGiftcardHandlerIdentifierFromTxVariant')->willReturn(null);

        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $factory = new WebhookHandlerFactory(
            $captureService,
            $adyenPaymentService,
            $this->getSimpleMock(RefundService::class),
            $this->stateHandler,
            $pluginPaymentMethods,
            $this->getSimpleMock(ConfigurationService::class),
            $logger
        );

        $handler = new ProcessNotificationsHandler(
            $this->getSimpleMock(EntityRepository::class),
            $this->notificationService,
            $orderRepository,
            $this->getSimpleMock(EntityRepository::class),
            $transactionRepository,
            $adyenPaymentService,
            $captureService,
            $factory,
            $paymentResponseService,
            // Not used for orders that exist, and a readonly class cannot be mocked by every PHPUnit version.
            (new \ReflectionClass(PaypalPaymentService::class))->newInstanceWithoutConstructor()
        );
        $handler->setLogger($logger);
        $handler->run();

        $this->assertSame([], $this->errors);
    }
}
