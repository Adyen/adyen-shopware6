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
use Adyen\Shopware\Entity\PaymentCapture\PaymentCaptureEntity;
use Adyen\Shopware\ScheduledTask\Webhook\CaptureWebhookHandler;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Webhook\PaymentStates;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

class CaptureWebhookHandlerTest extends AdyenTestCase
{
    private CaptureService&MockObject $captureService;
    private OrderTransactionStateHandler&MockObject $stateHandler;
    private CaptureWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureService = $this->getSimpleMock(CaptureService::class);
        $this->stateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);
        $this->handler = new CaptureWebhookHandler(
            $this->captureService,
            $this->stateHandler,
            $this->getSimpleMock(Logger::class)
        );
    }

    public function testSuccessfulNotificationWithStateChangePaysTransaction()
    {
        $this->captureService->method('checkRequiredAmountFullyCapturedInNotification')->willReturn(true);
        $this->stateHandler->expects($this->once())->method('paid');
        $this->stateHandler->expects($this->never())->method('fail');
        $this->expectCaptureStoredWithStatus(PaymentCaptureEntity::STATUS_SUCCESS);

        $this->handleWebhook(true, PaymentStates::STATE_PAID, PaymentStates::STATE_PENDING);
    }

    public function testSuccessfulNotificationWithoutStateChangeStoresSuccessfulCapture()
    {
        $this->stateHandler->expects($this->never())->method('paid');
        $this->stateHandler->expects($this->never())->method('fail');
        $this->expectCaptureStoredWithStatus(PaymentCaptureEntity::STATUS_SUCCESS);

        $this->handleWebhook(true, PaymentStates::STATE_PAID, PaymentStates::STATE_PAID);
    }

    public function testFailedNotificationFailsAuthorizedTransaction()
    {
        $this->stateHandler->expects($this->once())->method('fail');
        $this->expectCaptureStoredWithStatus(PaymentCaptureEntity::STATUS_FAILED);

        $this->handleWebhook(false, PaymentStates::STATE_PENDING, PaymentStates::STATE_PENDING);
    }

    public function testFailedNotificationDoesNotFailPaidTransaction()
    {
        $this->stateHandler->expects($this->never())->method('fail');
        $this->expectCaptureStoredWithStatus(PaymentCaptureEntity::STATUS_FAILED);

        $this->handleWebhook(false, PaymentStates::STATE_PAID, PaymentStates::STATE_PAID);
    }

    private function expectCaptureStoredWithStatus(string $status): void
    {
        $this->captureService->expects($this->once())
            ->method('handleCaptureNotification')
            ->with($this->anything(), $this->anything(), $status);
    }

    private function handleWebhook(bool $success, string $state, string $currentTransactionState): void
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder(new OrderEntity());

        $notification = new NotificationEntity();
        $notification->setSuccess($success);

        $this->handler->handleWebhook(
            $orderTransaction,
            $notification,
            $state,
            $currentTransactionState,
            Context::createDefaultContext()
        );
    }
}
