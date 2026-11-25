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
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Models\Capture\CaptureConfiguration;
use Adyen\Shopware\Models\Capture\CaptureMode;
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
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * When the shopper does not return to the shop after the payment, the webhook is the only source of the original
 * PSP reference, which capture and refund need. Shops that use the same merchant account and have orders with the
 * same order number get the webhooks of each other, so only the webhooks of the transaction may be linked to it.
 */
class OriginalPspReferenceFromWebhookTest extends AdyenTestCase
{
    private const PSP_REFERENCE = 'PSP_AUTH';
    private const TRANSACTION_ID = '0190a1b2c3d4e5f60718293a4b5c6d7e';
    private const OTHER_SHOP_TRANSACTION_ID = '0190ffffffffffffffffffffffffffff';

    private OrderTransactionEntity $transaction;
    private AdyenPaymentService|MockObject $adyenPaymentService;
    private CaptureService|MockObject $captureService;
    private OrderTransactionStateHandler|MockObject $stateHandler;
    private NotificationService|MockObject $notificationService;
    private OrderRepository|MockObject $orderRepository;

    public function testAuthorisationLinkedOnlyByOrderNumberStoresNoReference(): void
    {
        // Without metadata and payment response it can be the payment of another shop with the same order number.
        $this->runScenario();

        $this->assertNull($this->getStoredReference());
    }

    public function testAuthorisationWithStoredPaymentResponseStoresItsPspReference(): void
    {
        $this->runScenario(['paymentResponsePsps' => [self::PSP_REFERENCE]]);

        $this->assertSame(self::PSP_REFERENCE, $this->getStoredReference());
    }

    public function testStoredPspReferenceIsNotOverwritten(): void
    {
        $this->runScenario(['storedReference' => 'PSP_FROM_RETURN']);

        $this->assertSame('PSP_FROM_RETURN', $this->getStoredReference());
    }

    public function testFailedAuthorisationStoresNoReference(): void
    {
        $this->runScenario(['success' => false]);

        $this->assertNull($this->getStoredReference());
    }

    public function testPartialPaymentOfAnAdyenOrderStoresNoReference(): void
    {
        $this->runScenario(['additionalData' => '{"merchantOrderReference":"another-reference"}']);

        $this->assertNull($this->getStoredReference());
    }

    public function testKeyIsSetWithoutReferenceAsRefundChecksIt(): void
    {
        $this->runScenario(['success' => false]);

        $this->assertArrayHasKey(
            PaymentResponseHandler::ORIGINAL_PSP_REFERENCE,
            $this->transaction->getCustomFields()
        );
    }

    public function testAuthorisationWithTransactionIdOfThisTransactionIsProcessed(): void
    {
        $this->runScenario(
            ['additionalData' => $this->metadata(self::TRANSACTION_ID)],
            function () {
                $this->adyenPaymentService->expects($this->once())->method('insertAdyenPayment');
                $this->stateHandler->expects($this->once())->method('authorize');
            }
        );

        $this->assertSame(self::PSP_REFERENCE, $this->getStoredReference());
    }

    public function testAuthorisationOfAnotherShopWithTheSameOrderNumberIsSkipped(): void
    {
        $this->runScenario(
            ['additionalData' => $this->metadata(self::OTHER_SHOP_TRANSACTION_ID)],
            function () {
                $this->adyenPaymentService->expects($this->never())->method('insertAdyenPayment');
                $this->stateHandler->expects($this->never())->method('authorize');
                $this->stateHandler->expects($this->never())->method('paid');
            }
        );

        $this->assertArrayNotHasKey(
            PaymentResponseHandler::ORIGINAL_PSP_REFERENCE,
            $this->transaction->getCustomFields()
        );
    }

    public function testAuthorisationOfTransactionOfAnotherOrderIsSkipped(): void
    {
        $this->runScenario(
            [
                'additionalData' => $this->metadata(self::TRANSACTION_ID),
                'transactionOrderId' => 'another-order',
                'transactionOrderNumber' => '20002',
            ],
            function () {
                $this->adyenPaymentService->expects($this->never())->method('insertAdyenPayment');
                $this->stateHandler->expects($this->never())->method('authorize');
            }
        );

        $this->assertArrayNotHasKey(
            PaymentResponseHandler::ORIGINAL_PSP_REFERENCE,
            $this->transaction->getCustomFields()
        );
    }

    public function testAuthorisationOfAnotherOrderOfThisShopWithTheSameOrderNumberIsProcessed(): void
    {
        // Sales channels with their own number ranges can have orders with the same order number.
        $this->runScenario(
            [
                'additionalData' => $this->metadata(self::TRANSACTION_ID),
                'transactionOrderId' => 'order-2',
            ],
            function () {
                $this->orderRepository->expects($this->once())->method('getOrder')
                    ->with('order-2')
                    ->willReturn($this->transaction->getOrder());
                $this->adyenPaymentService->expects($this->once())->method('insertAdyenPayment');
            }
        );

        $this->assertSame(self::PSP_REFERENCE, $this->getStoredReference());
    }

    public function testRefusedAuthorisationOfFailedTransactionIsProcessedWithoutError(): void
    {
        // The /payments call already returned the payment as refused and the transaction failed.
        $this->runScenario(
            [
                'success' => false,
                'additionalData' => $this->metadata(self::TRANSACTION_ID),
                'paymentResponsePsps' => [self::PSP_REFERENCE],
                'transactionState' => OrderTransactionStates::STATE_FAILED,
            ],
            function () {
                $this->notificationService->expects($this->never())->method('saveError');
            }
        );
    }

    public function testAuthorisationWithInvalidTransactionIdIsSkipped(): void
    {
        $this->runScenario(
            ['additionalData' => $this->metadata('not-a-transaction-id')],
            function () {
                $this->adyenPaymentService->expects($this->never())->method('insertAdyenPayment');
            }
        );

        $this->assertArrayNotHasKey(
            PaymentResponseHandler::ORIGINAL_PSP_REFERENCE,
            $this->transaction->getCustomFields()
        );
    }

    public function testCaptureOfAnotherPaymentIsSkipped(): void
    {
        $this->runScenario(
            [
                'eventCode' => 'CAPTURE',
                'success' => false,
                'pspReference' => 'PSP_CAPTURE',
                'originalReference' => 'PSP_OF_ANOTHER_SHOP',
                'storedReference' => self::PSP_REFERENCE,
                'paymentResponsePsps' => [self::PSP_REFERENCE],
            ],
            function () {
                $this->adyenPaymentService->method('isPaymentOfOrderTransaction')->willReturn(false);
                $this->stateHandler->expects($this->never())->method('fail');
                $this->captureService->expects($this->never())->method('handleCaptureNotification');
            }
        );

        $this->assertSame(self::PSP_REFERENCE, $this->getStoredReference());
    }

    public function testCaptureOfThePaymentOfTheTransactionIsProcessed(): void
    {
        $this->runScenario(
            [
                'eventCode' => 'CAPTURE',
                'pspReference' => 'PSP_CAPTURE',
                'originalReference' => self::PSP_REFERENCE,
                'storedReference' => self::PSP_REFERENCE,
            ],
            function () {
                $this->captureService->expects($this->once())->method('handleCaptureNotification');
            }
        );
    }

    public function testCaptureOfPartialPaymentOfTheTransactionIsProcessed(): void
    {
        $this->runScenario(
            [
                'eventCode' => 'CAPTURE',
                'pspReference' => 'PSP_CAPTURE',
                'originalReference' => 'PSP_PARTIAL_PAYMENT',
                'storedReference' => self::PSP_REFERENCE,
            ],
            function () {
                $this->adyenPaymentService->method('isPaymentOfOrderTransaction')->willReturnCallback(
                    fn (string $psp, string $transactionId) => $transactionId === self::TRANSACTION_ID
                        && in_array($psp, [self::PSP_REFERENCE, 'PSP_PARTIAL_PAYMENT'], true)
                );
                $this->captureService->expects($this->once())->method('handleCaptureNotification');
            }
        );
    }

    public function testCaptureOfPaymentReturnedForTheTransactionIsProcessed(): void
    {
        // The stored reference is of an earlier attempt, the payment of the retry has no adyen_payment row.
        $this->runScenario(
            [
                'eventCode' => 'CAPTURE',
                'pspReference' => 'PSP_CAPTURE',
                'originalReference' => 'PSP_RETRY',
                'storedReference' => self::PSP_REFERENCE,
                'paymentResponsePsps' => ['PSP_RETRY'],
            ],
            function () {
                $this->adyenPaymentService->method('isPaymentOfOrderTransaction')->willReturn(false);
                $this->captureService->expects($this->once())->method('handleCaptureNotification');
            }
        );
    }

    public function testCaptureIsProcessedWhenTheStoredReferenceIsNotAKnownPayment(): void
    {
        // e.g. the reference of one gift card of several, stored from a refund webhook of an older payment
        $this->runScenario(
            [
                'eventCode' => 'REFUND',
                'pspReference' => 'PSP_REFUND',
                'originalReference' => 'PSP_GIFTCARD_1',
                'storedReference' => 'PSP_GIFTCARD_2',
            ],
            function () {
                $this->adyenPaymentService->method('isPaymentOfOrderTransaction')->willReturn(false);
                $this->notificationService->expects($this->never())->method('saveError');
            }
        );
    }

    public function testAuthorisationWithoutMetadataOfAnotherPaymentIsSkipped(): void
    {
        // A shop with an older plugin version, while /payments returned another PSP reference for this transaction.
        $this->runScenario(
            ['pspReference' => 'PSP_OF_ANOTHER_SHOP', 'storedResponsePsp' => self::PSP_REFERENCE],
            function () {
                $this->adyenPaymentService->expects($this->never())->method('insertAdyenPayment');
                $this->stateHandler->expects($this->never())->method('authorize');
                $this->stateHandler->expects($this->never())->method('paid');
            }
        );

        $this->assertArrayNotHasKey(
            PaymentResponseHandler::ORIGINAL_PSP_REFERENCE,
            $this->transaction->getCustomFields()
        );
    }

    public function testAuthorisationWithoutMetadataIsProcessedWithoutReturnedPspReference(): void
    {
        $this->runScenario(
            [],
            function () {
                $this->stateHandler->expects($this->once())->method('authorize');
            }
        );
    }

    public function testPartialPaymentWithoutMetadataIsNotSkippedAsAnotherPayment(): void
    {
        $this->runScenario(
            [
                'pspReference' => 'PSP_GIFTCARD',
                'additionalData' => '{"merchantOrderReference":"another-reference"}',
                'storedResponsePsp' => self::PSP_REFERENCE,
            ],
            function () {
                $this->adyenPaymentService->expects($this->once())->method('insertAdyenPayment');
            }
        );
    }

    public function testAuthorisationOfTransactionChangedToAnotherPaymentMethodIsSkipped(): void
    {
        $this->runScenario(
            ['additionalData' => $this->metadata(self::TRANSACTION_ID), 'adyenPaymentMethod' => false],
            function () {
                $this->adyenPaymentService->expects($this->never())->method('insertAdyenPayment');
                $this->stateHandler->expects($this->never())->method('authorize');
            }
        );
    }

    public function testNotificationIsSkippedWhenTheOrderOfTheMetadataTransactionIsNotFound(): void
    {
        $this->runScenario(
            [
                'additionalData' => $this->metadata(self::TRANSACTION_ID),
                'transactionOrderId' => 'order-2',
            ],
            function () {
                $this->orderRepository->method('getOrder')->willReturn(null);
                $this->adyenPaymentService->expects($this->never())->method('insertAdyenPayment');
                $this->notificationService->expects($this->once())->method('saveError');
            }
        );
    }

    public function testCaptureOnShipmentLoadsTheOrderOfTheTransaction(): void
    {
        $this->runScenario(
            [
                'additionalData' => $this->metadata(self::TRANSACTION_ID),
                'transactionOrderId' => 'order-2',
                'captureOnShipment' => true,
            ],
            function () {
                $this->orderRepository->method('getOrder')->willReturn($this->transaction->getOrder());
                $this->captureService->expects($this->once())->method('doCapture')
                    ->with('10001', $this->anything(), $this->anything(), false, 'order-2');
            }
        );
    }

    public function testCaptureIsProcessedWhileThePaymentOfTheTransactionIsUnknown(): void
    {
        $this->runScenario(
            [
                'eventCode' => 'CAPTURE',
                'pspReference' => 'PSP_CAPTURE',
                'originalReference' => 'PSP_UNKNOWN',
            ],
            function () {
                $this->captureService->expects($this->once())->method('handleCaptureNotification');
            }
        );
    }

    private function metadata(string $orderTransactionId): string
    {
        return json_encode(['metadata.orderTransactionId' => $orderTransactionId]);
    }

    public function testOtherEventStoresItsOriginalReferenceOfAKnownPayment(): void
    {
        $this->runScenario([
            'eventCode' => 'CAPTURE',
            'pspReference' => 'PSP_CAPTURE',
            'originalReference' => self::PSP_REFERENCE,
            'paymentResponsePsps' => [self::PSP_REFERENCE],
        ]);

        $this->assertSame(self::PSP_REFERENCE, $this->getStoredReference());
    }

    public function testOtherEventWithMetadataStoresItsOriginalReference(): void
    {
        $this->runScenario([
            'eventCode' => 'CAPTURE',
            'pspReference' => 'PSP_CAPTURE',
            'originalReference' => self::PSP_REFERENCE,
            'additionalData' => $this->metadata(self::TRANSACTION_ID),
        ]);

        $this->assertSame(self::PSP_REFERENCE, $this->getStoredReference());
    }

    public function testOtherEventWithoutMetadataDoesNotStoreAnUnknownOriginalReference(): void
    {
        // e.g. a refund of another shop with an older plugin version, before the AUTHORISATION of this transaction
        $this->runScenario([
            'eventCode' => 'REFUND',
            'pspReference' => 'PSP_REFUND',
            'originalReference' => 'PSP_OF_ANOTHER_SHOP',
        ]);

        $this->assertNull($this->getStoredReference());
        $this->assertArrayHasKey(
            PaymentResponseHandler::ORIGINAL_PSP_REFERENCE,
            $this->transaction->getCustomFields()
        );
    }

    public function testLinkedAuthorisationReplacesAStoredReferenceThatIsNotAPaymentOfTheTransaction(): void
    {
        $this->runScenario([
            'additionalData' => $this->metadata(self::TRANSACTION_ID),
            'storedReference' => 'PSP_OF_ANOTHER_SHOP',
        ]);

        $this->assertSame(self::PSP_REFERENCE, $this->getStoredReference());
    }

    public function testLinkedAuthorisationKeepsAStoredReferenceThatIsAPaymentOfTheTransaction(): void
    {
        $this->runScenario([
            'additionalData' => $this->metadata(self::TRANSACTION_ID),
            'storedReference' => 'PSP_FROM_RETURN',
            'paymentResponsePsps' => ['PSP_FROM_RETURN'],
        ]);

        $this->assertSame('PSP_FROM_RETURN', $this->getStoredReference());
    }

    public function testAuthorisationWithoutLinkDoesNotReplaceTheStoredReference(): void
    {
        $this->runScenario([
            'pspReference' => 'PSP_OF_ANOTHER_SHOP',
            'storedReference' => 'PSP_UNKNOWN',
        ]);

        $this->assertSame('PSP_UNKNOWN', $this->getStoredReference());
    }

    private function getStoredReference(): ?string
    {
        return $this->transaction->getCustomFields()[PaymentResponseHandler::ORIGINAL_PSP_REFERENCE] ?? null;
    }

    private function runScenario(array $options = [], ?callable $expectations = null): void
    {
        $scenario = (object)array_merge([
            'eventCode' => 'AUTHORISATION',
            'success' => true,
            'pspReference' => self::PSP_REFERENCE,
            'originalReference' => null,
            'additionalData' => '{}',
            'storedReference' => null,
            'transactionOrderId' => 'order-1',
            'transactionOrderNumber' => '10001',
            'transactionState' => OrderTransactionStates::STATE_IN_PROGRESS,
            'paymentResponsePsps' => [],
            'storedResponsePsp' => null,
            'adyenPaymentMethod' => true,
            'captureOnShipment' => false,
        ], $options);

        $notification = new NotificationEntity();
        $notification->setId('notification-1');
        $notification->setEventCode($scenario->eventCode);
        $notification->setSuccess($scenario->success);
        $notification->setPspreference($scenario->pspReference);
        $notification->setMerchantReference('10001');
        $notification->setPaymentMethod('klarna');
        $notification->setAmountValue('1000');
        $notification->setAmountCurrency('EUR');
        $notification->setAdditionalData($scenario->additionalData);
        $notification->setErrorCount(0);
        // Hydrated as null by the DAL for an AUTHORISATION, but the setter does not accept null.
        $notification->assign(['originalReference' => $scenario->originalReference]);

        $this->notificationService = $notificationService = $this->getSimpleMock(NotificationService::class);
        $notificationService->method('getScheduledUnprocessedNotifications')
            ->willReturn(new NotificationEntityCollection([$notification]));

        $currency = new CurrencyEntity();
        $currency->setId('currency-eur');
        $currency->setIsoCode('EUR');

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setOrderNumber('10001');
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $transactionOrder = $order;
        if ($scenario->transactionOrderId !== $order->getId()) {
            $transactionOrder = new OrderEntity();
            $transactionOrder->setId($scenario->transactionOrderId);
            $transactionOrder->setOrderNumber($scenario->transactionOrderNumber);
            $transactionOrder->setSalesChannelId('sales-channel-1');
            $transactionOrder->setCurrency($currency);
        }

        $this->transaction = $this->createTransaction(
            $transactionOrder,
            $scenario->transactionState,
            $scenario->adyenPaymentMethod
        );
        $this->transaction->setOrderId($scenario->transactionOrderId);
        $this->transaction->setCustomFields(
            is_null($scenario->storedReference)
                ? []
                : [PaymentResponseHandler::ORIGINAL_PSP_REFERENCE => $scenario->storedReference]
        );
        $order->setTransactions(new OrderTransactionCollection([$this->transaction]));

        $this->orderRepository = $orderRepository = $this->getSimpleMock(OrderRepository::class);
        $orderRepository->method('getOrderByOrderNumber')->willReturn($order);

        // Without a return of the shopper there is no stored payment response.
        $paymentResponseService = $this->getSimpleMock(PaymentResponseService::class);
        $paymentResponseService->method('getWithPspreference')->willReturnCallback(
            function (string $pspReference) use ($scenario) {
                if (!in_array($pspReference, $scenario->paymentResponsePsps, true)) {
                    return null;
                }

                $paymentResponse = new PaymentResponseEntity();
                $paymentResponse->setOrderTransactionId(self::TRANSACTION_ID);

                return $paymentResponse;
            }
        );
        $paymentResponseService->method('getWithOrderTransaction')->willReturnCallback(
            function () use ($scenario) {
                if (is_null($scenario->storedResponsePsp)) {
                    return null;
                }

                $paymentResponse = new PaymentResponseEntity();
                $paymentResponse->setOrderTransactionId(self::TRANSACTION_ID);
                $paymentResponse->setPspreference($scenario->storedResponsePsp);

                return $paymentResponse;
            }
        );

        $transactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $transactionRepository->method('getWithId')->willReturnCallback(
            fn (string $id) => $id === self::TRANSACTION_ID ? $this->transaction : null
        );
        $transactionRepository->method('getFirstAdyenOrderTransactionByStates')->willReturn($this->transaction);
        $transactionRepository->method('getFirstAdyenOrderTransaction')->willReturn($this->transaction);
        $transactionRepository->method('getAdyenOrderTransactionsByStates')
            ->willReturn(new OrderTransactionCollection([$this->transaction]));

        $this->captureService = $captureService = $this->getSimpleMock(CaptureService::class);
        $captureService->method('getCaptureConfiguration')->willReturn(
            new CaptureConfiguration($scenario->captureOnShipment ? CaptureMode::ON_SHIPMENT : CaptureMode::MANUAL)
        );

        $pluginPaymentMethods = $this->getSimpleMock(PluginPaymentMethodsService::class);
        $pluginPaymentMethods->method('getGiftcardHandlerIdentifierFromTxVariant')->willReturn(null);

        $this->adyenPaymentService = $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $adyenPaymentService->method('getMerchantReferenceFromOrderReference')->willReturn('10001');
        $logger = $this->createMock(LoggerInterface::class);
        $this->stateHandler = $stateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);

        $factory = new WebhookHandlerFactory(
            $captureService,
            $adyenPaymentService,
            $this->getSimpleMock(RefundService::class),
            $stateHandler,
            $pluginPaymentMethods,
            $this->getSimpleMock(ConfigurationService::class),
            $logger
        );

        $handler = new ProcessNotificationsHandler(
            $this->getSimpleMock(EntityRepository::class),
            $notificationService,
            $orderRepository,
            $this->getSimpleMock(EntityRepository::class),
            $transactionRepository,
            $adyenPaymentService,
            $captureService,
            $factory,
            $paymentResponseService,
            // Not used for orders that exist, and a readonly class cannot be mocked by every PHPUnit version.
            (new \ReflectionClass(PaypalPaymentService::class))->newInstanceWithoutConstructor(),
            $stateHandler
        );
        $handler->setLogger($logger);

        if ($expectations) {
            $expectations();
        }

        $handler->run();
    }

    private function createTransaction(
        OrderEntity $order,
        string $stateName,
        bool $isAdyenPaymentMethod
    ): OrderTransactionEntity {
        $state = new StateMachineStateEntity();
        $state->setId('state-tx-1');
        $state->setTechnicalName($stateName);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('payment-method-1');
        $paymentMethod->setHandlerIdentifier('Adyen\Shopware\Handlers\KlarnaPayLaterPaymentMethodHandler');
        $plugin = new PluginEntity();
        $plugin->setName($isAdyenPaymentMethod ? ConfigurationService::BUNDLE_NAME : 'AnotherPaymentPlugin');
        $paymentMethod->setPlugin($plugin);

        $transaction = new OrderTransactionEntity();
        $transaction->setId(self::TRANSACTION_ID);
        $transaction->setStateMachineState($state);
        $transaction->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $transaction->setAmount(
            new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection())
        );
        $transaction->setOrder($order);
        $transaction->setPaymentMethod($paymentMethod);

        return $transaction;
    }
}
