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
use Adyen\Shopware\Models\NotificationProcessingResult;
use Adyen\Shopware\ScheduledTask\ProcessNotificationsHandler;
use Adyen\Shopware\ScheduledTask\Webhook\WebhookHandlerFactory;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaypalPaymentService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class ProcessNotificationsHandlerProcessingResultTest extends AdyenTestCase
{
    private MockObject $notificationService;
    private ProcessNotificationsHandler $handler;
    private ?bool $done = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationService = $this->getSimpleMock(NotificationService::class);
        $this->notificationService->method('changeNotificationState')
            ->willReturnCallback(function (string $id, string $property, bool $state) {
                if ($property === 'done') {
                    $this->done = $state;
                }
            });

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
            // Not used when applying a result, and a readonly class cannot be mocked by every PHPUnit version.
            (new \ReflectionClass(PaypalPaymentService::class))->newInstanceWithoutConstructor()
        );
        $this->handler->setLogger($this->createMock(LoggerInterface::class));
    }

    public function testDoneMarksTheNotificationAsDone(): void
    {
        $this->notificationService->expects($this->never())->method('saveError');
        $this->notificationService->expects($this->never())->method('setNotificationSchedule');

        $this->apply($this->createNotification(), NotificationProcessingResult::done());

        $this->assertTrue($this->done);
    }

    public function testDoneWithErrorRecordsTheErrorAndMarksTheNotificationAsDone(): void
    {
        $this->notificationService->expects($this->once())
            ->method('saveError')
            ->with('notification-1', 'PSP reference is missing.', 1);
        $this->notificationService->expects($this->never())->method('setNotificationSchedule');

        $this->apply($this->createNotification(), NotificationProcessingResult::done('PSP reference is missing.'));

        $this->assertTrue($this->done);
    }

    public function testRescheduleUsesTheGivenTimeWithoutRecordingAnError(): void
    {
        $scheduledProcessingTime = new \DateTime('+20 minutes');

        $this->notificationService->expects($this->never())->method('saveError');
        $this->notificationService->expects($this->once())
            ->method('setNotificationSchedule')
            ->with('notification-1', $scheduledProcessingTime);

        $this->apply($this->createNotification(), NotificationProcessingResult::reschedule($scheduledProcessingTime));

        $this->assertNull($this->done);
    }

    public function testRetryRecordsTheErrorAndReschedules(): void
    {
        $this->notificationService->expects($this->once())->method('saveError')->with('notification-1');
        $this->notificationService->expects($this->once())->method('setNotificationSchedule');

        $this->apply($this->createNotification(), NotificationProcessingResult::retry('Reversal failed.'));

        $this->assertNull($this->done);
    }

    public function testRetryMarksTheNotificationAsDoneAfterTheMaximumErrorCount(): void
    {
        $this->notificationService->expects($this->once())->method('saveError')->with('notification-1');
        $this->notificationService->expects($this->never())->method('setNotificationSchedule');

        $this->apply(
            $this->createNotification(ProcessNotificationsHandler::MAX_ERROR_COUNT),
            NotificationProcessingResult::retry('Reversal failed.')
        );

        $this->assertTrue($this->done);
    }

    private function apply(NotificationEntity $notification, NotificationProcessingResult $result): void
    {
        $method = new \ReflectionMethod(ProcessNotificationsHandler::class, 'applyProcessingResult');
        // Required before PHP 8.1.
        $method->setAccessible(true);
        $method->invoke($this->handler, $notification, $result);
    }

    private function createNotification(int $errorCount = 0): NotificationEntity
    {
        $notification = new NotificationEntity();
        $notification->setId('notification-1');
        $notification->setMerchantReference('10001');
        $notification->setErrorCount($errorCount);

        return $notification;
    }
}
