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
use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
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
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * A successful AUTHORISATION or ORDER_CLOSED must settle a transaction that Shopware failed only because the
 * /payments/details call failed on the shopper's return. A successful AUTHORISATION of a new payment, e.g. after the
 * first payment was refused, must settle the failed transaction if the payment metadata links it to the transaction,
 * or, without the metadata, if the payment of the transaction was refused and the amount and currency match. The
 * transaction stays failed if the order was cancelled or another transaction replaced or settled it.
 */
class AuthorisationAfterFailedTransactionTest extends AdyenTestCase
{
    /**
     * The payment metadata carries a real transaction ID, the handler accepts only a valid UUID there.
     */
    private const TRANSACTION_UUIDS = ['tx-1' => '0190a1b2c3d47e8f9a0b1c2d3e4f5a61'];

    private MockObject $stateHandler;
    private MockObject $notificationService;
    private ?bool $done = null;
    private array $errors = [];
    private array $transitions = [];
    private array $captures = [];
    private array $savedCustomFields = [];
    private array $insertedPayments = [];
    private int $insertAttempts = 0;
    private array $schedules = [];
    /** @var OrderTransactionEntity[] */
    private array $transactions = [];
    /**
     * State and custom fields as stored in the database. Like the real OrderTransactionStateHandler, a transition
     * changes only the database, never an entity that was loaded before.
     *
     * @var array<string, array{state: string, customFields: array}>
     */
    private array $database = [];

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
                    $this->database[$id]['state'] = $targetState;
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
            'captureMode' => CaptureMode::MANUAL,
        ]);

        $this->assertSame(['process:tx-1', 'authorize:tx-1'], $this->transitions);
        $this->assertSame([], $this->captures);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testDelayedCaptureAuthorisesATransactionFailedByThePaymentDetailsCall(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'captureMode' => CaptureMode::DELAYED,
        ]);

        $this->assertSame(['process:tx-1', 'authorize:tx-1'], $this->transitions);
        $this->assertSame([], $this->captures);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testCaptureOnShipmentAuthorisesAndCapturesATransactionFailedByThePaymentDetailsCall(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'captureMode' => CaptureMode::ON_SHIPMENT,
        ]);

        $this->assertSame(['process:tx-1', 'authorize:tx-1'], $this->transitions);
        $this->assertSame(['10001:1000'], $this->captures);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testManualCaptureOrderClosedAuthorisesASplitPaymentFailedByThePaymentDetailsCall(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'eventCode' => 'ORDER_CLOSED',
            'storedPspReference' => false,
            'captureMode' => CaptureMode::MANUAL,
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

    public function testNewPaymentWithMetadataPaysATransactionFailedByARefusedAuthorisation(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
        ]);

        $this->assertSame(['process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertSame('PSP_B', $this->database['tx-1']['customFields']['originalPspReference']);
        $this->assertSame(['PSP_B'], $this->insertedPayments);
        $this->assertEmpty($this->database['tx-1']['customFields'][ResultHandler::PAYMENT_DETAILS_CALL_FAILED] ?? null);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testNewPaymentWithMetadataPaysAFailedTransactionWithoutAKnownRefusal(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
        ]);

        $this->assertSame(['process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertSame([], $this->errors);
    }

    public function testManualCaptureNewPaymentWithMetadataAuthorisesAFailedTransaction(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
            'captureMode' => CaptureMode::MANUAL,
        ]);

        $this->assertSame(['process:tx-1', 'authorize:tx-1'], $this->transitions);
        $this->assertSame('PSP_B', $this->database['tx-1']['customFields']['originalPspReference']);
        $this->assertSame([], $this->errors);
    }

    public function testNewPaymentWithoutMetadataPaysATransactionFailedByARefusedAuthorisation(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'refusedPsp' => 'PSP_A',
        ]);

        $this->assertSame(['process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertSame('PSP_B', $this->database['tx-1']['customFields']['originalPspReference']);
        $this->assertSame(['PSP_B'], $this->insertedPayments);
        $this->assertSame([], $this->errors);
        $this->assertTrue($this->done);
    }

    public function testNewPaymentWithoutMetadataIsNotAppliedWhenTheOwnPaymentWasNotRefused(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
        $this->assertTrue($this->done);
    }

    public function testNewPaymentWithoutMetadataIsNotAppliedForAnotherAmount(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'refusedPsp' => 'PSP_A',
            'amountValue' => '999',
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
    }

    public function testNewPaymentWithoutMetadataIsNotAppliedForAnotherCurrency(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'refusedPsp' => 'PSP_A',
            'currency' => 'USD',
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
    }

    public function testNewPaymentIsNotAppliedToACancelledOrder(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
            'orderState' => OrderStates::STATE_CANCELLED,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('refunded', $this->errors[0]);
        $this->assertTrue($this->done);
    }

    public function testNewPaymentIsNotAppliedWhenAnotherTransactionOfTheOrderIsPaid(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
            'olderTransactionState' => OrderTransactionStates::STATE_PAID,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('refunded', $this->errors[0]);
    }

    public function testNewPaymentIsNotAppliedWhenANewerTransactionReplacedTheFailedOne(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
            'newerTransactionState' => OrderTransactionStates::STATE_IN_PROGRESS,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('refunded', $this->errors[0]);
    }

    public function testPartialPaymentWithMetadataIsNotAppliedToAFailedTransaction(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
            'merchantOrderReference' => 'adyen-order-1',
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertSame([], $this->insertedPayments);
    }

    public function testNewPaymentWithMetadataForAnotherAmountIsNotApplied(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'amountValue' => '500',
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertSame([], $this->insertedPayments);
        $this->assertCount(1, $this->errors);
    }

    public function testNewPaymentWithoutMetadataPaysWhenOnlyThePaymentResponseKnowsTheRefusedPayment(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'refusedPsp' => 'PSP_A',
            'storedPspReference' => false,
            'paymentResponsePsp' => 'PSP_A',
        ]);

        $this->assertSame(['process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertSame('PSP_B', $this->database['tx-1']['customFields']['originalPspReference']);
    }

    public function testNewPaymentWithoutMetadataIsNotAppliedWithoutAKnownPaymentOfTheTransaction(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'refusedPsp' => 'PSP_A',
            'storedPspReference' => false,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
    }

    public function testNewPaymentWithoutMetadataComparesTheAmountOfAZeroDecimalCurrency(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'refusedPsp' => 'PSP_A',
            'orderCurrency' => 'JPY',
            'currency' => 'JPY',
            'amountValue' => '10',
        ]);

        $this->assertSame(['process:tx-1', 'paid:tx-1'], $this->transitions);
    }

    public function testSuccessfulOrderClosedWithoutMarkerLeavesAFailedTransactionFailed(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'eventCode' => 'ORDER_CLOSED',
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
        ]);

        $this->assertSame([], $this->transitions);
    }

    public function testFailedAuthorisationOfANewPaymentLeavesAFailedTransactionUnchanged(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'success' => false,
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
        ]);

        $this->assertNotContains('process:tx-1', $this->transitions);
        $this->assertNotContains('paid:tx-1', $this->transitions);
        $this->assertSame('PSP_A', $this->database['tx-1']['customFields']['originalPspReference']);
    }

    public function testTransactionReopenedForANewPaymentIsFailedAgainWithoutMarkerWhenProcessingThrows(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
            'insertThrows' => true,
        ]);

        $this->assertSame(['process:tx-1', 'fail:tx-1'], $this->transitions);
        $this->assertEmpty($this->database['tx-1']['customFields'][ResultHandler::PAYMENT_DETAILS_CALL_FAILED] ?? null);
        $this->assertSame('PSP_A', $this->database['tx-1']['customFields']['originalPspReference']);
        $this->assertNotTrue($this->done);
        $this->assertCount(1, $this->errors);
    }

    public function testNewPaymentWithMetadataIsSettledByTheRetryAfterProcessingThrew(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
            'insertThrowsOnce' => true,
            'runs' => 2,
        ]);

        $this->assertSame(['process:tx-1', 'fail:tx-1', 'process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertSame('PSP_B', $this->database['tx-1']['customFields']['originalPspReference']);
    }

    public function testNewPaymentWithoutMetadataIsSettledByTheRetryAfterProcessingThrew(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'refusedPsp' => 'PSP_A',
            'insertThrowsOnce' => true,
            'runs' => 2,
        ]);

        $this->assertSame(['process:tx-1', 'fail:tx-1', 'process:tx-1', 'paid:tx-1'], $this->transitions);
        $this->assertSame('PSP_B', $this->database['tx-1']['customFields']['originalPspReference']);
    }

    public function testNewPaymentIsRescheduledWhileTheRefusalOfThePaymentOfTheTransactionIsPending(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_IN_PROGRESS, [
            'notificationPsp' => 'PSP_B',
            'pendingRefusedPsp' => 'PSP_A',
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertSame([], $this->errors);
        $this->assertNotTrue($this->done);
        $this->assertCount(1, $this->schedules);
    }

    public function testNewPaymentWithoutMetadataIsSkippedAsAnotherPaymentOfAnInProgressTransactionAsBefore(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_IN_PROGRESS, [
            'notificationPsp' => 'PSP_B',
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('another shop', $this->errors[0]);
        $this->assertSame([], $this->schedules);
        $this->assertTrue($this->done);
    }

    public function testRetriedAuthorisationOfARecoveredPaymentWithoutMetadataIsCapturedOnShipment(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_AUTHORIZED, [
            'notificationPsp' => 'PSP_B',
            'originalPspReference' => 'PSP_B',
            'adyenPaymentPsps' => ['PSP_B'],
            'captureMode' => CaptureMode::ON_SHIPMENT,
        ]);

        $this->assertSame([], $this->errors);
        $this->assertSame(['10001:1000'], $this->captures);
    }

    public function testTransactionFailedByThePaymentDetailsCallIsNotReopenedForACancelledOrder(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'paymentDetailsCallFailed' => true,
            'orderState' => OrderStates::STATE_CANCELLED,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertStringContainsString('refunded', $this->errors[0]);
    }

    public function testNewPaymentIsNotAppliedWhenAnotherTransactionOfTheOrderIsAuthorised(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'notificationPsp' => 'PSP_B',
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
            'olderTransactionState' => OrderTransactionStates::STATE_AUTHORIZED,
        ]);

        $this->assertSame([], $this->transitions);
        $this->assertCount(1, $this->errors);
    }

    public function testSuccessfulAuthorisationPaysAnInProgressTransactionWithMetadataAsBefore(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_IN_PROGRESS, [
            'metadataTransactionId' => 'tx-1',
        ]);

        $this->assertSame(['paid:tx-1'], $this->transitions);
        $this->assertSame([], $this->errors);
    }

    public function testLateSuccessOfTheRefusedPaymentLeavesTheTransactionFailed(): void
    {
        $this->runScenario(OrderTransactionStates::STATE_FAILED, [
            'metadataTransactionId' => 'tx-1',
            'refusedPsp' => 'PSP_A',
        ]);

        $this->assertSame([], $this->transitions);
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
            'captureMode' => CaptureMode::IMMEDIATE,
            'newerTransactionState' => null,
            'olderTransactionState' => null,
            'amountValue' => '1000',
            'currency' => 'EUR',
            'notificationPsp' => 'PSP_A',
            'metadataTransactionId' => null,
            'refusedPsp' => null,
            'orderState' => null,
            'merchantOrderReference' => null,
            'paymentResponsePsp' => null,
            'orderCurrency' => 'EUR',
            'insertThrows' => false,
            'insertThrowsOnce' => false,
            'runs' => 1,
            'originalPspReference' => null,
            'adyenPaymentPsps' => [],
            'pendingRefusedPsp' => null,
        ], $options);
        $responsePsp = $scenario->paymentResponsePsp ?? ($scenario->storedPspReference ? 'PSP_A' : null);

        $additionalData = [];
        if (!is_null($scenario->metadataTransactionId)) {
            $additionalData['metadata.orderTransactionId'] =
                self::TRANSACTION_UUIDS[$scenario->metadataTransactionId];
        }
        if (!is_null($scenario->merchantOrderReference)) {
            $additionalData['merchantOrderReference'] = $scenario->merchantOrderReference;
        }

        $notification = new NotificationEntity();
        $notification->setId('notification-1');
        $notification->setEventCode($scenario->eventCode);
        $notification->setSuccess($scenario->success);
        $notification->setPspreference($scenario->notificationPsp);
        $notification->setMerchantReference('10001');
        $notification->setPaymentMethod('ideal');
        $notification->setAmountValue($scenario->amountValue);
        $notification->setAmountCurrency($scenario->currency);
        $notification->setAdditionalData(json_encode((object)$additionalData));
        $notification->setErrorCount(0);
        // Hydrated as null by the DAL for an AUTHORISATION, but the setter does not accept null.
        $notification->assign(['originalReference' => null]);
        $this->notificationService->method('getScheduledUnprocessedNotifications')
            ->willReturn(new NotificationEntityCollection([$notification]));

        $currency = new CurrencyEntity();
        $currency->setId('currency-eur');
        $currency->setIsoCode($scenario->orderCurrency);

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setOrderNumber('10001');
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);
        if (!is_null($scenario->orderState)) {
            $orderState = new StateMachineStateEntity();
            $orderState->setId('order-state');
            $orderState->setTechnicalName($scenario->orderState);
            $order->setStateMachineState($orderState);
        }

        $transaction = $this->createTransaction('tx-1', $scenario->state, new \DateTimeImmutable('-1 hour'), $order);
        $customFields = $scenario->storedPspReference
            ? ['originalPspReference' => $scenario->originalPspReference ?? 'PSP_A']
            : [];
        if ($scenario->paymentDetailsCallFailed) {
            $customFields[ResultHandler::PAYMENT_DETAILS_CALL_FAILED] = true;
        }
        $transaction->setCustomFields($customFields);

        $this->transactions = [];
        $this->database = [];
        if (!is_null($scenario->olderTransactionState)) {
            $this->transactions['tx-0'] = $this->createTransaction(
                'tx-0',
                $scenario->olderTransactionState,
                new \DateTimeImmutable('-2 hours'),
                $order
            );
            $this->database['tx-0'] = ['state' => $scenario->olderTransactionState, 'customFields' => []];
        }
        $this->transactions['tx-1'] = $transaction;
        $this->database['tx-1'] = ['state' => $scenario->state, 'customFields' => $customFields];
        if (!is_null($scenario->newerTransactionState)) {
            $this->transactions['tx-2'] = $this->createTransaction(
                'tx-2',
                $scenario->newerTransactionState,
                new \DateTimeImmutable(),
                $order
            );
            $this->database['tx-2'] = ['state' => $scenario->newerTransactionState, 'customFields' => []];
        }
        $order->setTransactions(new OrderTransactionCollection($this->transactions));

        $orderRepository = $this->getSimpleMock(OrderRepository::class);
        $orderRepository->method('getOrderByOrderNumber')->willReturn($order);

        $paymentResponseService = $this->getSimpleMock(PaymentResponseService::class);
        if (!is_null($responsePsp)) {
            $paymentResponse = $this->getSimpleMock(PaymentResponseEntity::class);
            $paymentResponse->method('getOrderTransactionId')->willReturn('tx-1');
            $paymentResponse->method('getPspreference')->willReturn($responsePsp);
            $paymentResponseService->method('getWithPspreference')
                ->willReturnCallback(fn (string $psp) => $psp === $responsePsp ? $paymentResponse : null);
            $paymentResponseService->method('getWithOrderTransaction')
                ->willReturnCallback(
                    fn (OrderTransactionEntity $tx) => $tx->getId() === 'tx-1' ? $paymentResponse : null
                );
        } else {
            $paymentResponseService->method('getWithPspreference')->willReturn(null);
            $paymentResponseService->method('getWithOrderTransaction')->willReturn(null);
        }

        $this->notificationService->method('hasProcessedFailedAuthorisation')
            ->willReturnCallback(fn (string $merchantReference, array $pspReferences) =>
                !is_null($scenario->refusedPsp) && in_array($scenario->refusedPsp, $pspReferences, true));
        $this->notificationService->method('getPendingFailedAuthorisation')
            ->willReturnCallback(function (string $merchantReference, array $pspReferences) use ($scenario) {
                if (is_null($scenario->pendingRefusedPsp) ||
                    !in_array($scenario->pendingRefusedPsp, $pspReferences, true)) {
                    return null;
                }
                $pending = new NotificationEntity();
                $pending->setId('notification-refused');
                $pending->setPspreference($scenario->pendingRefusedPsp);
                // Hydrated by the DAL, null until the notification is scheduled.
                $pending->assign(['scheduledProcessingTime' => null]);

                return $pending;
            });
        $this->notificationService->method('calculateScheduledProcessingTime')
            ->willReturn(new \DateTime('+30 minutes'));
        $this->notificationService->method('setNotificationSchedule')
            ->willReturnCallback(function (string $id, \DateTimeInterface $time) {
                $this->schedules[] = $id;
            });

        $transactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $transactionRepository->method('getWithId')->willReturnCallback(
            fn (string $id) => $this->loadTransaction(array_search($id, self::TRANSACTION_UUIDS, true) ?: $id)
        );
        $transactionRepository->method('getFirstAdyenOrderTransactionByStates')
            ->willReturnCallback(fn (string $orderId, array $states) => $this->getByStates($states)->first());
        $transactionRepository->method('getAdyenOrderTransactionsByStates')
            ->willReturnCallback(fn (string $orderId, array $states) => $this->getByStates($states));
        $transactionRepository->method('updateCustomFields')
            ->willReturnCallback(function (OrderTransactionEntity $updated) {
                $this->savedCustomFields[] = $updated->getCustomFields();
                $this->database[$updated->getId()]['customFields'] = $updated->getCustomFields();
            });

        $captureService = $this->getSimpleMock(CaptureService::class);
        $captureService->method('getCaptureConfiguration')->willReturn(
            new CaptureConfiguration($scenario->captureMode, $scenario->captureMode === CaptureMode::DELAYED ? 3 : null)
        );
        $captureService->method('doCapture')
            ->willReturnCallback(function (string $orderNumber, $amount) {
                $this->captures[] = $orderNumber . ':' . $amount;

                return [];
            });

        $pluginPaymentMethods = $this->getSimpleMock(PluginPaymentMethodsService::class);
        $pluginPaymentMethods->method('getGiftcardHandlerIdentifierFromTxVariant')->willReturn(null);

        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $adyenPaymentService->method('getMerchantReferenceFromOrderReference')->willReturn('10001');
        $adyenPaymentService->method('insertAdyenPayment')
            ->willReturnCallback(function (NotificationEntity $inserted) use ($scenario) {
                if ($scenario->insertThrows || ($scenario->insertThrowsOnce && $this->insertAttempts++ === 0)) {
                    throw new \RuntimeException('The adyen_payment row could not be stored.');
                }
                $this->insertedPayments[] = $inserted->getPspreference();
            });
        $adyenPaymentService->method('isPaymentOfOrderTransaction')
            ->willReturnCallback(fn (string $psp, string $transactionId) =>
                in_array($psp, $scenario->adyenPaymentPsps, true));
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
            $this->getSimpleMock(PaypalPaymentService::class),
            $this->stateHandler
        );
        $handler->setLogger($logger);
        for ($run = 0; $run < $scenario->runs; $run++) {
            $handler->run();
        }
    }

    /**
     * Newest transaction first, as the repository returns them.
     */
    private function getByStates(array $states): OrderTransactionCollection
    {
        $transactions = [];
        foreach (array_reverse(array_keys($this->transactions)) as $id) {
            if (in_array($this->database[$id]['state'], $states, true)) {
                $transactions[$id] = $this->loadTransaction($id);
            }
        }

        return new OrderTransactionCollection($transactions);
    }

    /**
     * A fresh entity with the state and custom fields currently stored in the database.
     */
    private function loadTransaction(string $id): ?OrderTransactionEntity
    {
        if (!isset($this->transactions[$id])) {
            return null;
        }

        $stored = $this->transactions[$id];
        $transaction = $this->createTransaction(
            $id,
            $this->database[$id]['state'],
            $stored->getCreatedAt(),
            $stored->getOrder()
        );
        $transaction->setCustomFields($this->database[$id]['customFields']);

        return $transaction;
    }

    private function createTransaction(
        string $id,
        string $technicalName,
        \DateTimeInterface $createdAt,
        OrderEntity $order
    ): OrderTransactionEntity {
        $state = new StateMachineStateEntity();
        $state->setId('state-' . $id);
        $state->setTechnicalName($technicalName);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('payment-method-1');
        $paymentMethod->setHandlerIdentifier('Adyen\Shopware\Handlers\IdealPaymentMethodHandler');
        $plugin = new PluginEntity();
        $plugin->setName(ConfigurationService::BUNDLE_NAME);
        $paymentMethod->setPlugin($plugin);

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
