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
use Adyen\Shopware\Handlers\ResultHandler;
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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * A successful AUTHORISATION or ORDER_CLOSED must settle a transaction that Shopware failed only because the
 * /payments/details call failed on the shopper's return, and must leave any other failed transaction unchanged.
 */
class AuthorisationAfterFailedTransactionTest extends AdyenTestCase
{
    private MockObject $stateHandler;
    private MockObject $notificationService;
    private ?bool $done = null;
    private array $errors = [];
    private array $transitions = [];
    private array $savedCustomFields = [];
    /** @var OrderTransactionEntity[] */
    private array $transactions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);
        $targetStates = [
            'process' => OrderTransactionStates::STATE_IN_PROGRESS,
            'paid' => OrderTransactionStates::STATE_PAID,
            'authorize' => OrderTransactionStates::STATE_AUTHORIZED,
            'fail' => OrderTransactionStates::STATE_FAILED,
        ];
        foreach ($targetStates as $transition => $targetState) {
            $this->stateHandler->method($transition)
                ->willReturnCallback(function (string $id) use ($transition, $targetState) {
                    $this->transitions[] = $transition . ':' . $id;
                    $this->transactions[$id]->getStateMachineState()->setTechnicalName($targetState);
                });
        }

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
        $this->runScenario(OrderTransactionStates::STATE_IN_PROGRESS);

        $this->assertSame(['paid:tx-1'], $this->transitions);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testSuccessfulAuthorisationReopensAndPaysATransactionFailedByThePaymentDetailsCall(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
        ]);

        $this->assertSame(['process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertNull(end($this->savedCustomFields)[ResultHandler::PAYMENT_DETAILS_CALL_FAILED]);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testManualCaptureAuthorisesATransactionFailedByThePaymentDetailsCall(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'manualCapture' => true,
        ]);

        $this->assertSame(['process:tx-1', 'authorize:tx-1'], $this->transitions);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testRedirectPaymentWithoutStoredPspReferenceIsFoundAndPaid(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'storedPspReference' => false,
        ]);

        $this->assertSame(['process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testLateSuccessfulAuthorisationLeavesACancelledTransactionFailed(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED);

        $this->assertSame([], $this->transitions);
        $this->assertTrue($this->done);
    }

    public function testLateSuccessfulAuthorisationLeavesACancelledRedirectTransactionFailed(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'storedPspReference' => false,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertTrue($this->done);
    }

    public function testSuccessfulAuthorisationIsNotAppliedWhenANewerTransactionReplacedTheFailedOne(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'newerTransactionState' => OrderTransactionStates::STATE_PAID,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('refunded', $this->errors[0]);
        $this->assertTrue($this->done);
    }

    public function testOlderMarkedTransactionIsFoundBehindANewerRefusedOneAndReportedForRefund(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'storedPspReference' => false,
            'newerTransactionState' => OrderTransactionStates::STATE_FAILED,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('refunded', $this->errors[0]);
        $this->assertTrue($this->done);
    }

    public function testReopenedTransactionIsFailedAgainWhenTheNotificationDoesNotSettleIt(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'amountValue' => '999',
        ]);

        $this->assertSame(['process:tx-1', 'fail:tx-1'], $this->transitions);
        $this->assertTrue(end($this->savedCustomFields)[ResultHandler::PAYMENT_DETAILS_CALL_FAILED]);
        $this->assertTrue($this->done);
    }

    public function testFailedAuthorisationLeavesATransactionFailedByThePaymentDetailsCallUnchanged(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'success' => false,
        ]);

        $this->assertNotContains('process:tx-1', $this->transitions);
        $this->assertNotContains('paid:tx-1', $this->transitions);
        $this->assertNotContains('authorize:tx-1', $this->transitions);
        $this->assertTrue($this->done);
    }

    public function testSuccessfulOrderClosedPaysASplitPaymentFailedByThePaymentDetailsCall(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'eventCode' => 'ORDER_CLOSED',
            'storedPspReference' => false,
        ]);

        $this->assertSame(['process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    private function runScenario(string $state, array $options = []): void
    {
        $scenario = (object)array_merge([
            'state' => $state,
            'eventCode' => 'AUTHORISATION',
            'success' => true,
            'paymentDetailsCallFailed' => false,
            'storedPspReference' => true,
            'manualCapture' => false,
            'newerTransactionState' => null,
            'amountValue' => '1000',
        ], $options);

        $notification = new NotificationEntity();
        $notification->setId('notification-1');
        $notification->setEventCode($scenario->eventCode);
        $notification->setSuccess($scenario->success);
        $notification->setPspreference('PSP_A');
        $notification->setMerchantReference('10001');
        $notification->setPaymentMethod('ideal');
        $notification->setAmountValue($scenario->amountValue);
        $notification->setAmountCurrency('EUR');
        $notification->setAdditionalData('{}');
        $notification->setErrorCount(0);
        // Hydrated as null by the DAL for an AUTHORISATION, but the setter does not accept null.
        $notification->assign(['originalReference' => null]);
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

        $transaction = $this->createTransaction('tx-1', $scenario->state, new \DateTimeImmutable('-1 hour'), $order);
        $customFields = $scenario->storedPspReference ? ['originalPspReference' => 'PSP_A'] : [];
        if ($scenario->paymentDetailsCallFailed) {
            $customFields[ResultHandler::PAYMENT_DETAILS_CALL_FAILED] = true;
        }
        $transaction->setCustomFields($customFields);

        $this->transactions = ['tx-1' => $transaction];
        if (!is_null($scenario->newerTransactionState)) {
            $this->transactions['tx-2'] = $this->createTransaction(
                'tx-2',
                $scenario->newerTransactionState,
                new \DateTimeImmutable(),
                $order
            );
        }
        $order->setTransactions(new OrderTransactionCollection($this->transactions));

        $orderRepository = $this->getSimpleMock(OrderRepository::class);
        $orderRepository->method('getOrderByOrderNumber')->willReturn($order);

        $paymentResponseService = $this->getSimpleMock(PaymentResponseService::class);
        if ($scenario->storedPspReference) {
            $paymentResponse = $this->getSimpleMock(PaymentResponseEntity::class);
            $paymentResponse->method('getOrderTransactionId')->willReturn('tx-1');
            $paymentResponseService->method('getWithPspreference')->with('PSP_A')->willReturn($paymentResponse);
        } else {
            $paymentResponseService->method('getWithPspreference')->willReturn(null);
        }

        $transactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $transactionRepository->method('getWithId')
            ->willReturnCallback(fn (string $id) => $this->transactions[$id] ?? null);
        $transactionRepository->method('getFirstAdyenOrderTransactionByStates')
            ->willReturnCallback(fn (string $orderId, array $states) => $this->getByStates($states)->first());
        $transactionRepository->method('getAdyenOrderTransactionsByStates')
            ->willReturnCallback(fn (string $orderId, array $states) => $this->getByStates($states));
        $transactionRepository->method('updateCustomFields')
            ->willReturnCallback(function (OrderTransactionEntity $updated) {
                $this->savedCustomFields[] = $updated->getCustomFields();
            });

        $captureService = $this->getSimpleMock(CaptureService::class);
        $captureService->method('isManualCaptureActive')->willReturn($scenario->manualCapture);
        $captureService->method('isCaptureOnShipmentEnabled')->willReturn(false);
        $captureService->method('isManualCapture')->willReturn($scenario->manualCapture);
        $captureService->method('requiresCaptureOnShipment')->willReturn(false);

        $pluginPaymentMethods = $this->getSimpleMock(PluginPaymentMethodsService::class);
        $pluginPaymentMethods->method('getGiftcardHandlerIdentifierFromTxVariant')->willReturn(null);

        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $adyenPaymentService->method('getMerchantReferenceFromOrderReference')->willReturn('10001');
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
            $logger,
            $this->notificationService,
            $orderRepository,
            $this->getSimpleMock(EntityRepository::class),
            $transactionRepository,
            $adyenPaymentService,
            $captureService,
            $factory,
            $paymentResponseService,
            // Not used for orders that exist, and a readonly class cannot be mocked by every PHPUnit version.
            (new \ReflectionClass(PaypalPaymentService::class))->newInstanceWithoutConstructor(),
            $this->stateHandler
        );
        $handler->setLogger($logger);
        $handler->run();
    }

    /**
     * Newest transaction first, as the repository returns them.
     */
    private function getByStates(array $states): OrderTransactionCollection
    {
        $transactions = array_filter(
            array_reverse($this->transactions),
            fn (OrderTransactionEntity $transaction) => in_array(
                $transaction->getStateMachineState()->getTechnicalName(),
                $states,
                true
            )
        );

        return new OrderTransactionCollection($transactions);
    }

    private function createTransaction(
        string $id,
        string $technicalName,
        \DateTimeImmutable $createdAt,
        OrderEntity $order
    ): OrderTransactionEntity {
        $state = new StateMachineStateEntity();
        $state->setId('state-' . $id);
        $state->setTechnicalName($technicalName);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('payment-method-1');
        $paymentMethod->setHandlerIdentifier('Adyen\Shopware\Handlers\IdealPaymentMethodHandler');

        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setStateMachineState($state);
        $transaction->setCreatedAt($createdAt);
        $transaction->setAmount(
            new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection())
        );
        $transaction->setOrder($order);
        $transaction->setPaymentMethod($paymentMethod);
        $transaction->setCustomFields([]);

        return $transaction;
    }
}
