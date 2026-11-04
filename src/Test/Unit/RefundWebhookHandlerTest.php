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
use Adyen\Shopware\ScheduledTask\Webhook\RefundWebhookHandler;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Webhook\PaymentStates;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;

class RefundWebhookHandlerTest extends AdyenTestCase
{
    private RefundService&MockObject $refundService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refundService = $this->getSimpleMock(RefundService::class);
    }

    public function testFailedNotificationStoresFailedRefund()
    {
        $this->refundService->expects($this->once())
            ->method('handleRefundNotification')
            ->with($this->anything(), $this->anything(), RefundEntity::STATUS_FAILED);
        $this->refundService->expects($this->never())->method('handleSuccessfulRefundNotification');
        $this->refundService->expects($this->never())->method('doRefund');

        $this->handleWebhook(false, PaymentStates::STATE_PAID, PaymentStates::STATE_PAID);
    }

    public function testSuccessfulNotificationWithStateChangeRefundsTransaction()
    {
        $this->refundService->expects($this->once())->method('handleSuccessfulRefundNotification')->willReturn(true);
        $this->refundService->expects($this->once())
            ->method('doRefund')
            ->with($this->anything(), OrderTransactionStates::STATE_REFUNDED);

        $this->handleWebhook(true, PaymentStates::STATE_REFUNDED, PaymentStates::STATE_PAID);
    }

    public function testSuccessfulNotificationWithStateChangeWithoutAdyenPaymentKeepsState()
    {
        $this->refundService->method('handleSuccessfulRefundNotification')->willReturn(false);
        $this->refundService->expects($this->never())->method('doRefund');

        $this->handleWebhook(true, PaymentStates::STATE_REFUNDED, PaymentStates::STATE_PAID);
    }

    public function testSuccessfulNotificationForRefundedTransactionStoresSuccessfulRefund()
    {
        $this->refundService->expects($this->once())->method('handleSuccessfulRefundNotification');
        $this->refundService->expects($this->never())->method('handleRefundNotification');
        $this->refundService->expects($this->never())->method('doRefund');

        $this->handleWebhook(true, PaymentStates::STATE_REFUNDED, PaymentStates::STATE_REFUNDED);
    }

    public function testSuccessfulNotificationWithoutStateChangeForAuthorizedTransactionIsHandledAsBefore()
    {
        $this->refundService->expects($this->never())->method('handleSuccessfulRefundNotification');
        $this->refundService->expects($this->once())
            ->method('handleRefundNotification')
            ->with($this->anything(), $this->anything(), RefundEntity::STATUS_FAILED);
        $this->refundService->expects($this->never())->method('doRefund');

        $this->handleWebhook(true, PaymentStates::STATE_PENDING, PaymentStates::STATE_PENDING);
    }

    private function handleWebhook(bool $success, string $state, string $currentTransactionState): void
    {
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

        (new RefundWebhookHandler($this->refundService))->handleWebhook(
            $orderTransaction,
            $notification,
            $state,
            $currentTransactionState,
            Context::createDefaultContext()
        );
    }
}
