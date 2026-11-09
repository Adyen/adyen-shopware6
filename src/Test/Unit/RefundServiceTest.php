<?php
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

use Adyen\Shopware\Entity\AdyenPayment\AdyenPaymentEntity;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Shopware\Util\Currency;
use Adyen\Shopware\Util\Idempotency;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class RefundServiceTest extends AdyenTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testIsAmountRefundableNoRefunds()
    {
        $order = $this->createThrowAwayOrder('1', 100.01);
        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection([]));

        $refundService = $this->createMockedRefundService([
            AdyenRefundRepository::class => $refundRepository,
            Currency::class => new Currency()
        ]);

        $this->assertTrue($refundService->isAmountRefundable($order, '5000'));
    }

    public function testIsAmountRefundableFullRefundFalse()
    {
        $order = $this->createThrowAwayOrder('1', 100.01);
        $refund = $this->createThrowAwayRefund('1', 10001, RefundEntity::STATUS_SUCCESS);
        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection([$refund]));

        $refundService = $this->createMockedRefundService([
            AdyenRefundRepository::class => $refundRepository,
            Currency::class => new Currency()
        ]);

        $this->assertFalse($refundService->isAmountRefundable($order, '1'));
    }

    public function testIsAmountRefundablePartialRefundsTrue()
    {
        $order = $this->createThrowAwayOrder('1', 333.33);
        $refunds = [
            $this->createThrowAwayRefund('1', 22233, RefundEntity::STATUS_SUCCESS),
            $this->createThrowAwayRefund('2', 11100, RefundEntity::STATUS_FAILED),
            $this->createThrowAwayRefund('3', 5200, RefundEntity::STATUS_SUCCESS)
        ];

        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection($refunds));

        $refundService = $this->createMockedRefundService([
            AdyenRefundRepository::class => $refundRepository,
            Currency::class => new Currency()
        ]);

        $this->assertTrue($refundService->isAmountRefundable($order, '5900'));
    }

    public function testHandleRefundNotificationStoresRefundForRefundedTransaction()
    {
        $order = $this->createThrowAwayOrder('1', 100.00);
        $orderTransaction = $this->createOrderTransaction('transaction-1');

        $orderTransactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $orderTransactionRepository->expects($this->exactly(2))
            ->method('getFirstAdyenOrderTransactionByStates')
            ->with('1', RefundService::REFUND_TRANSACTION_STATES)
            ->willReturn($orderTransaction);

        $entityRepository = $this->getSimpleMock(EntityRepository::class);
        $entityRepository->expects($this->once())
            ->method('create')
            ->with([[
                'orderTransactionId' => 'transaction-1',
                'pspReference' => 'REFUND_PSP',
                'source' => RefundEntity::SOURCE_ADYEN,
                'status' => RefundEntity::STATUS_SUCCESS,
                'amount' => 10000
            ]]);

        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundForOrderByPspReference')->willReturn(null);
        $refundRepository->method('getRepository')->willReturn($entityRepository);

        $refundService = $this->createMockedRefundService([
            OrderTransactionRepository::class => $orderTransactionRepository,
            AdyenRefundRepository::class => $refundRepository
        ]);

        $this->assertContains(OrderTransactionStates::STATE_REFUNDED, RefundService::REFUND_TRANSACTION_STATES);
        $this->assertTrue($refundService->handleRefundNotification(
            $order,
            $this->createNotification('REFUND_PSP', '10000'),
            RefundEntity::STATUS_SUCCESS
        ));
    }

    public function testHandleRefundNotificationUpdatesStatusOfStoredRefund()
    {
        $refund = $this->createThrowAwayRefund('refund-1', 10000, RefundEntity::STATUS_PENDING_WEBHOOK);

        $entityRepository = $this->getSimpleMock(EntityRepository::class);
        $entityRepository->expects($this->never())->method('create');
        $entityRepository->expects($this->once())
            ->method('update')
            ->with([['id' => 'refund-1', 'status' => RefundEntity::STATUS_SUCCESS]]);

        $refundService = $this->createRefundServiceWithStoredRefund($refund, $entityRepository);

        $this->assertTrue($refundService->handleRefundNotification(
            $this->createThrowAwayOrder('1', 100.00),
            $this->createNotification('REFUND_PSP', '10000'),
            RefundEntity::STATUS_SUCCESS
        ));
    }

    public function testHandleRefundNotificationDoesNotUpdateRefundStoredWithSameStatus()
    {
        $refund = $this->createThrowAwayRefund('refund-1', 10000, RefundEntity::STATUS_SUCCESS);

        $entityRepository = $this->getSimpleMock(EntityRepository::class);
        $entityRepository->expects($this->never())->method('create');
        $entityRepository->expects($this->never())->method('update');

        $refundService = $this->createRefundServiceWithStoredRefund($refund, $entityRepository);

        $this->assertFalse($refundService->handleRefundNotification(
            $this->createThrowAwayOrder('1', 100.00),
            $this->createNotification('REFUND_PSP', '10000'),
            RefundEntity::STATUS_SUCCESS
        ));
    }

    public function testInsertAdyenRefundSearchesRefundableStatesByDefault()
    {
        $orderTransactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $orderTransactionRepository->expects($this->once())
            ->method('getFirstAdyenOrderTransactionByStates')
            ->with('1', RefundService::REFUNDABLE_STATES)
            ->willReturn($this->createOrderTransaction('transaction-1'));

        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRepository')->willReturn($this->getSimpleMock(EntityRepository::class));

        $refundService = $this->createMockedRefundService([
            OrderTransactionRepository::class => $orderTransactionRepository,
            AdyenRefundRepository::class => $refundRepository
        ]);

        $refundService->insertAdyenRefund(
            $this->createThrowAwayOrder('1', 100.00),
            'REFUND_PSP',
            RefundEntity::SOURCE_SHOPWARE,
            RefundEntity::STATUS_PENDING_WEBHOOK,
            10000
        );
    }

    public function testUpdateTotalRefundedAmountUpdatesAdyenPaymentOfNotification()
    {
        $adyenPayment = new AdyenPaymentEntity();

        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $adyenPaymentService->method('getAdyenPayment')->with('PAYMENT_PSP')->willReturn($adyenPayment);
        $adyenPaymentService->expects($this->once())
            ->method('updateTotalRefundedAmount')
            ->with($adyenPayment, 10000);

        $refundService = $this->createMockedRefundService([AdyenPaymentService::class => $adyenPaymentService]);

        $this->assertTrue($refundService->updateTotalRefundedAmount($this->createNotification('REFUND_PSP', '10000')));
    }

    public function testUpdateTotalRefundedAmountReturnsFalseWithoutAdyenPayment()
    {
        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $adyenPaymentService->method('getAdyenPayment')->willReturn(null);
        $adyenPaymentService->expects($this->never())->method('updateTotalRefundedAmount');

        $refundService = $this->createMockedRefundService([AdyenPaymentService::class => $adyenPaymentService]);

        $this->assertFalse($refundService->updateTotalRefundedAmount($this->createNotification('REFUND_PSP', '10000')));
    }

    public function testHandleSuccessfulRefundNotificationCountsNewRefund()
    {
        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $adyenPaymentService->method('getAdyenPayment')->willReturn(new AdyenPaymentEntity());
        $adyenPaymentService->expects($this->once())->method('updateTotalRefundedAmount');

        $refundService = $this->createRefundServiceWithStoredRefund(
            $this->createThrowAwayRefund('refund-1', 10000, RefundEntity::STATUS_PENDING_WEBHOOK),
            $this->getSimpleMock(EntityRepository::class),
            $adyenPaymentService
        );

        $this->assertTrue($refundService->handleSuccessfulRefundNotification(
            $this->createThrowAwayOrder('1', 100.00),
            $this->createNotification('REFUND_PSP', '10000')
        ));
    }

    public function testHandleSuccessfulRefundNotificationDoesNotCountRefundTwice()
    {
        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $adyenPaymentService->method('getAdyenPayment')->willReturn(new AdyenPaymentEntity());
        $adyenPaymentService->expects($this->never())->method('updateTotalRefundedAmount');

        $refundService = $this->createRefundServiceWithStoredRefund(
            $this->createThrowAwayRefund('refund-1', 10000, RefundEntity::STATUS_SUCCESS),
            $this->getSimpleMock(EntityRepository::class),
            $adyenPaymentService
        );

        $this->assertTrue($refundService->handleSuccessfulRefundNotification(
            $this->createThrowAwayOrder('1', 100.00),
            $this->createNotification('REFUND_PSP', '10000')
        ));
    }

    public function testHandleSuccessfulRefundNotificationReturnsFalseWithoutAdyenPayment()
    {
        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $adyenPaymentService->method('getAdyenPayment')->willReturn(null);

        $refundService = $this->createRefundServiceWithStoredRefund(
            $this->createThrowAwayRefund('refund-1', 10000, RefundEntity::STATUS_PENDING_WEBHOOK),
            $this->getSimpleMock(EntityRepository::class),
            $adyenPaymentService
        );

        $this->assertFalse($refundService->handleSuccessfulRefundNotification(
            $this->createThrowAwayOrder('1', 100.00),
            $this->createNotification('REFUND_PSP', '10000')
        ));
    }

    private function createRefundServiceWithStoredRefund(
        RefundEntity $refund,
        EntityRepository $entityRepository,
        ?AdyenPaymentService $adyenPaymentService = null
    ): RefundService {
        $orderTransactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $orderTransactionRepository->method('getFirstAdyenOrderTransactionByStates')
            ->willReturn($this->createOrderTransaction('transaction-1'));

        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundForOrderByPspReference')->willReturn($refund);
        $refundRepository->method('getRepository')->willReturn($entityRepository);

        return $this->createMockedRefundService([
            OrderTransactionRepository::class => $orderTransactionRepository,
            AdyenRefundRepository::class => $refundRepository,
            AdyenPaymentService::class => $adyenPaymentService ?? $this->getSimpleMock(AdyenPaymentService::class)
        ]);
    }

    private function createOrderTransaction(string $id): OrderTransactionEntity
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId($id);
        $orderTransaction->setCustomFields([PaymentResponseHandler::ORIGINAL_PSP_REFERENCE => 'PAYMENT_PSP']);

        return $orderTransaction;
    }

    private function createNotification(string $pspReference, string $amountValue): NotificationEntity
    {
        $notification = new NotificationEntity();
        $notification->setPspreference($pspReference);
        $notification->setOriginalReference('PAYMENT_PSP');
        $notification->setAmountValue($amountValue);

        return $notification;
    }

    /**
     * Use default mocks to create RefundService, except for what is passed in the array
     * Not using a for loop to ensure better readability
     *
     * @param array $classArguments
     * @return RefundService
     */
    private function createMockedRefundService(array $classArguments) : RefundService
    {
        $logger = $this->getSimpleMock(Logger::class);
        $configService = $this->getSimpleMock(ConfigurationService::class);
        $clientService = $this->getSimpleMock(ClientService::class);
        $refundRepo = $this->getSimpleMock(AdyenRefundRepository::class);
        $currency = $this->getSimpleMock(Currency::class);
        $orderTransactionRepo = $this->getSimpleMock(OrderTransactionRepository::class);
        $orderTransactionStateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);
        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);
        $idempotency = $this->getSimpleMock(Idempotency::class);

        if (array_key_exists(Logger::class, $classArguments)) {
            $logger = $classArguments[Logger::class];
        }

        if (array_key_exists(ConfigurationService::class, $classArguments)) {
            $configService = $classArguments[ConfigurationService::class];
        }

        if (array_key_exists(ClientService::class, $classArguments)) {
            $clientService = $classArguments[ClientService::class];
        }

        if (array_key_exists(AdyenRefundRepository::class, $classArguments)) {
            $refundRepo = $classArguments[AdyenRefundRepository::class];
        }

        if (array_key_exists(Currency::class, $classArguments)) {
            $currency = $classArguments[Currency::class];
        }

        if (array_key_exists(OrderTransactionRepository::class, $classArguments)) {
            $orderTransactionRepo = $classArguments[OrderTransactionRepository::class];
        }

        if (array_key_exists(OrderTransactionStateHandler::class, $classArguments)) {
            $orderTransactionStateHandler = $classArguments[OrderTransactionStateHandler::class];
        }

        if (array_key_exists(AdyenPaymentService::class, $classArguments)) {
            $adyenPaymentService = $classArguments[AdyenPaymentService::class];
        }

        if (array_key_exists(Idempotency::class, $classArguments)) {
            $idempotency = $classArguments[Idempotency::class];
        }

        return new RefundService(
            $logger,
            $configService,
            $clientService,
            $refundRepo,
            $currency,
            $orderTransactionRepo,
            $orderTransactionStateHandler,
            $adyenPaymentService,
            $idempotency
        );
    }
}
