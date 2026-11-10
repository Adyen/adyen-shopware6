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

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\ScheduledTask\Webhook\CancelOrRefundWebhookHandler;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Webhook\PaymentStates;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;

class CancelOrRefundWebhookHandlerTest extends AdyenTestCase
{
    private MockObject $refundService;
    private MockObject $stateHandler;
    private CancelOrRefundWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refundService = $this->getSimpleMock(RefundService::class);
        $this->stateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);
        $this->handler = new CancelOrRefundWebhookHandler($this->refundService, $this->stateHandler);
    }

    public function testSuccessfulRefundWithStateChangeRefundsTransaction()
    {
        $this->refundService->expects($this->once())->method('handleSuccessfulRefundNotification');
        $this->refundService->expects($this->once())
            ->method('doRefund')
            ->with($this->anything(), OrderTransactionStates::STATE_REFUNDED);

        $this->handleWebhook(true, 'refund', PaymentStates::STATE_REFUNDED, PaymentStates::STATE_PAID);
    }

    public function testSuccessfulRefundForRefundedTransactionStoresSuccessfulRefund()
    {
        $this->refundService->expects($this->once())->method('handleSuccessfulRefundNotification');
        $this->refundService->expects($this->never())->method('handleRefundNotification');
        $this->refundService->expects($this->never())->method('doRefund');

        $this->handleWebhook(true, 'refund', PaymentStates::STATE_REFUNDED, PaymentStates::STATE_REFUNDED);
    }

    public function testSuccessfulRefundWithoutStateChangeForAuthorizedTransactionIsIgnored()
    {
        $this->refundService->expects($this->never())->method('handleSuccessfulRefundNotification');
        $this->refundService->expects($this->never())->method('handleRefundNotification');
        $this->refundService->expects($this->never())->method('doRefund');

        $this->handleWebhook(true, 'refund', PaymentStates::STATE_PENDING, PaymentStates::STATE_PENDING);
    }

    public function testSuccessfulCancellationForRefundedTransactionDoesNotStoreRefund()
    {
        $this->refundService->expects($this->never())->method('handleSuccessfulRefundNotification');
        $this->refundService->expects($this->never())->method('handleRefundNotification');
        $this->stateHandler->expects($this->never())->method('fail');

        $this->handleWebhook(true, 'cancel', PaymentStates::STATE_REFUNDED, PaymentStates::STATE_REFUNDED);
    }

    public function testSuccessfulCancellationForPaidTransactionDoesNotStoreFailedRefund()
    {
        $this->refundService->expects($this->never())->method('handleRefundNotification');
        $this->stateHandler->expects($this->never())->method('fail');

        $this->handleWebhook(true, 'cancel', PaymentStates::STATE_PAID, PaymentStates::STATE_PAID);
    }

    public function testSuccessfulCancellationCancelsTransaction()
    {
        $this->refundService->expects($this->never())->method('handleRefundNotification');
        $this->stateHandler->expects($this->once())->method('fail');

        $this->handleWebhook(true, 'cancel', PaymentStates::STATE_CANCELLED, PaymentStates::STATE_PENDING);
    }

    public function testFailedRefundStoresFailedRefund()
    {
        $this->refundService->expects($this->once())
            ->method('handleRefundNotification')
            ->with($this->anything(), $this->anything(), RefundEntity::STATUS_FAILED);
        $this->refundService->expects($this->never())->method('handleSuccessfulRefundNotification');

        $this->handleWebhook(false, 'refund', PaymentStates::STATE_PAID, PaymentStates::STATE_PAID);
    }

    private function handleWebhook(
        bool $success,
        string $modificationAction,
        string $state,
        string $currentTransactionState
    ): void {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $order = $this->createThrowAwayOrder('1', 100.00);
        $order->setCurrency($currency);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);
        $orderTransaction->setAmount(
            new CalculatedPrice(100.00, 100.00, new CalculatedTaxCollection(), new TaxRuleCollection())
        );

        $notification = new NotificationEntity();
        $notification->setSuccess($success);
        $notification->setPspreference('REFUND_PSP');
        $notification->setOriginalReference('PAYMENT_PSP');
        $notification->setAmountValue('10000');
        $notification->setAdditionalData(json_encode(['modification.action' => $modificationAction]));

        $this->handler->handleWebhook(
            $orderTransaction,
            $notification,
            $state,
            $currentTransactionState,
            Context::createDefaultContext()
        );
    }
}
