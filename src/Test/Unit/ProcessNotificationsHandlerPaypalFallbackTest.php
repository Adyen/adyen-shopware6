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
use Adyen\Shopware\Entity\PaypalPaymentAttempt\PaypalPaymentAttemptEntity;
use Adyen\Shopware\ScheduledTask\ProcessNotificationsHandler;
use Adyen\Shopware\ScheduledTask\Webhook\WebhookHandlerFactory;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaymentReversalService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Service\Repository\PaypalPaymentAttemptRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Webhook\EventCodes;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class ProcessNotificationsHandlerPaypalFallbackTest extends AdyenTestCase
{
    private MockObject $notificationService;
    private MockObject $paypalPaymentAttemptRepository;
    private MockObject $paymentReversalService;
    private ProcessNotificationsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationService = $this->getSimpleMock(NotificationService::class);
        $this->paypalPaymentAttemptRepository = $this->getSimpleMock(PaypalPaymentAttemptRepository::class);
        $this->paymentReversalService = $this->getSimpleMock(PaymentReversalService::class);

        $this->handler = new ProcessNotificationsHandler(
            $this->getSimpleMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $this->notificationService,
            $this->getSimpleMock(OrderRepository::class),
            $this->getSimpleMock(EntityRepository::class),
            $this->getSimpleMock(OrderTransactionRepository::class),
            $this->getSimpleMock(AdyenPaymentService::class),
            $this->getSimpleMock(CaptureService::class),
            $this->getSimpleMock(WebhookHandlerFactory::class),
            $this->getSimpleMock(PaymentResponseService::class),
            $this->paypalPaymentAttemptRepository,
            $this->paymentReversalService
        );
        $this->handler->setLogger($this->createMock(LoggerInterface::class));
    }

    public function testSuccessfulAuthorisationWithinGracePeriodIsRescheduled(): void
    {
        $gracePeriodEnd = new \DateTime('+20 minutes');
        $this->notificationService->method('getMissingOrderGracePeriodEnd')->willReturn($gracePeriodEnd);

        $this->notificationService->expects($this->once())
            ->method('setNotificationSchedule')
            ->with('notification-1', $gracePeriodEnd);
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $this->handle($this->createNotification(EventCodes::AUTHORISATION, true), $this->createAttempt());
    }

    public function testSuccessfulAuthorisationAfterGracePeriodReversesThePayment(): void
    {
        $this->notificationService->method('getMissingOrderGracePeriodEnd')->willReturn(null);
        $done = false;

        $this->paymentReversalService->expects($this->once())
            ->method('reverse')
            ->with('sales-channel-1', 'PSP123', '10001', $this->anything())
            ->willReturn('REVERSAL1');
        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('saveReversalOutcome')
            ->with('10001', 'REVERSAL1');
        $this->notificationService->expects($this->never())->method('setNotificationSchedule');
        $this->notificationService->expects($this->atLeastOnce())
            ->method('changeNotificationState')
            ->willReturnCallback(function (string $id, string $property, bool $state) use (&$done) {
                if ($property === 'done') {
                    $done = $state;
                }
            });

        $this->handle($this->createNotification(EventCodes::AUTHORISATION, true), $this->createAttempt());

        $this->assertTrue($done);
    }

    public function testFailedReversalIsRetried(): void
    {
        $this->notificationService->method('getMissingOrderGracePeriodEnd')->willReturn(null);
        $this->paymentReversalService->method('reverse')->willReturn(null);

        $this->paypalPaymentAttemptRepository->expects($this->once())
            ->method('saveReversalOutcome')
            ->with('10001', null);
        $this->notificationService->expects($this->once())->method('saveError')->with('notification-1');
        $this->notificationService->expects($this->once())->method('setNotificationSchedule');

        $this->handle(
            $this->createNotification(EventCodes::AUTHORISATION, true),
            $this->createAttempt(PaypalPaymentAttemptEntity::STATUS_REVERSAL_FAILED)
        );
    }

    public function testAlreadyReversedAttemptIsNotReversedAgain(): void
    {
        $this->paymentReversalService->expects($this->never())->method('reverse');
        $this->notificationService->expects($this->never())->method('setNotificationSchedule');

        $this->handle(
            $this->createNotification(EventCodes::AUTHORISATION, true),
            $this->createAttempt(PaypalPaymentAttemptEntity::STATUS_REVERSED)
        );
    }

    public function testReversalResultNotificationIsNotReversed(): void
    {
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $this->handle(
            $this->createNotification(EventCodes::CANCEL_OR_REFUND, true),
            $this->createAttempt(PaypalPaymentAttemptEntity::STATUS_REVERSED)
        );
    }

    public function testUnsuccessfulAuthorisationIsNotReversed(): void
    {
        $this->paymentReversalService->expects($this->never())->method('reverse');

        $this->handle($this->createNotification(EventCodes::AUTHORISATION, false), $this->createAttempt());
    }

    private function handle(NotificationEntity $notification, PaypalPaymentAttemptEntity $attempt): void
    {
        $method = new \ReflectionMethod(ProcessNotificationsHandler::class, 'handlePaypalPaymentWithoutOrder');
        $method->invoke($this->handler, $notification, $attempt, []);
    }

    private function createNotification(string $eventCode, bool $success): NotificationEntity
    {
        $notification = new NotificationEntity();
        $notification->setId('notification-1');
        $notification->setEventCode($eventCode);
        $notification->setSuccess($success);
        $notification->setPspreference('PSP123');
        $notification->setMerchantReference('10001');
        $notification->setErrorCount(0);
        $notification->setCreatedAt(new \DateTime('-1 hour'));

        return $notification;
    }

    private function createAttempt(string $status = PaypalPaymentAttemptEntity::STATUS_OPEN): PaypalPaymentAttemptEntity
    {
        $attempt = new PaypalPaymentAttemptEntity();
        $attempt->setId('attempt-1');
        $attempt->setMerchantReference('10001');
        $attempt->setSalesChannelId('sales-channel-1');
        $attempt->setStatus($status);

        return $attempt;
    }
}
