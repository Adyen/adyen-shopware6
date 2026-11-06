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
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Exception\CaptureException;
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
use Adyen\Shopware\Test\Common\CaptureWebhookTestTrait;
use Adyen\Webhook\EventCodes;
use DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * The capture related paths of ProcessNotificationsHandler::run() with the real AUTHORISATION webhook handler.
 */
class ProcessNotificationsHandlerCaptureTest extends AdyenTestCase
{
    use CaptureWebhookTestTrait;

    private MockObject $notificationService;
    private MockObject $orderTransactionRepository;
    private MockObject $captureService;
    private MockObject $stateHandler;
    private ProcessNotificationsHandler $handler;

    /**
     * @var array<string, bool> Last value set per notification state property ('done', 'processing').
     */
    private array $notificationState = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationService = $this->getSimpleMock(NotificationService::class);
        $this->notificationService->method('getScheduledUnprocessedNotifications')->willReturn(
            new EntityCollection([$this->createCaptureNotification(EventCodes::AUTHORISATION)])
        );
        $this->notificationService->method('changeNotificationState')
            ->willReturnCallback(function (string $id, string $property, bool $state): void {
                $this->notificationState[$property] = $state;
            });

        $orderTransaction = $this->createOrderTransaction(OrderTransactionStates::STATE_IN_PROGRESS);
        $orderRepository = $this->getSimpleMock(OrderRepository::class);
        $orderRepository->method('getOrderByOrderNumber')->willReturn($orderTransaction->getOrder());

        $this->orderTransactionRepository = $this->getSimpleMock(OrderTransactionRepository::class);
        $this->orderTransactionRepository->method('getFirstAdyenOrderTransactionByStates')
            ->willReturn($orderTransaction);

        $this->captureService = $this->getSimpleMock(CaptureService::class);
        $this->stateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);
        $adyenPaymentService = $this->getSimpleMock(AdyenPaymentService::class);

        // The factory keeps its dependencies in static properties; the handler creates webhook handlers from them.
        $webhookHandlerFactory = new WebhookHandlerFactory(
            $this->captureService,
            $adyenPaymentService,
            $this->getSimpleMock(RefundService::class),
            $this->stateHandler,
            $this->getSimpleMock(PluginPaymentMethodsService::class),
            $this->getSimpleMock(ConfigurationService::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->handler = new ProcessNotificationsHandler(
            $this->getSimpleMock(EntityRepository::class),
            $this->notificationService,
            $orderRepository,
            $this->getSimpleMock(EntityRepository::class),
            $this->orderTransactionRepository,
            $adyenPaymentService,
            $this->captureService,
            $webhookHandlerFactory,
            $this->getSimpleMock(PaymentResponseService::class),
            (new \ReflectionClass(PaypalPaymentService::class))->newInstanceWithoutConstructor(),
            $this->stateHandler
        );
        $this->handler->setLogger($this->createMock(LoggerInterface::class));
    }

    /**
     * The processor decides authorized vs. paid from the capture mode of this payment, not the global one.
     */
    public function testManualCaptureModeOfThePaymentAuthorisesTheTransaction(): void
    {
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::MANUAL));

        $this->stateHandler->expects(self::once())->method('authorize');
        $this->stateHandler->expects(self::never())->method('paid');

        $this->handler->run();

        self::assertTrue($this->notificationState['done'] ?? false);
    }

    public function testAutomaticCaptureModeOfThePaymentSetsTheTransactionToPaid(): void
    {
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::IMMEDIATE));

        $this->stateHandler->expects(self::once())->method('paid');
        $this->stateHandler->expects(self::never())->method('authorize');

        $this->handler->run();

        self::assertTrue($this->notificationState['done'] ?? false);
    }

    public function testDeliveryStateMismatchReschedulesWithTheRetryIntervalOfThePayment(): void
    {
        $configuration = new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'state-shipped', 600);
        $this->givenCaptureConfiguration($configuration);
        $this->givenCaptureFails(CaptureService::REASON_DELIVERY_STATE_MISMATCH);

        $scheduledTime = new DateTime('+600 seconds');
        $this->captureService->expects(self::once())
            ->method('getRescheduleNotificationTime')
            ->with($configuration)
            ->willReturn($scheduledTime);
        $this->notificationService->expects(self::once())
            ->method('setNotificationSchedule')
            ->with('notification-1', $scheduledTime);
        $this->notificationService->expects(self::never())->method('saveError');

        $this->handler->run();

        self::assertArrayNotHasKey('done', $this->notificationState);
    }

    public function testNothingToCaptureFinishesTheNotificationWithoutAnError(): void
    {
        $this->givenCaptureConfiguration(new CaptureConfiguration(CaptureMode::ON_SHIPMENT, null, 'state-shipped', 60));
        $this->givenCaptureFails(CaptureService::REASON_NOTHING_TO_CAPTURE);

        $this->notificationService->expects(self::never())->method('saveError');
        $this->notificationService->expects(self::never())->method('setNotificationSchedule');

        $this->handler->run();

        self::assertTrue($this->notificationState['done'] ?? false);
    }

    private function givenCaptureConfiguration(CaptureConfiguration $configuration): void
    {
        $this->captureService->method('getCaptureConfiguration')
            ->with(self::isInstanceOf(OrderTransactionEntity::class), 'sales-channel-1')
            ->willReturn($configuration);
    }

    private function givenCaptureFails(string $reason): void
    {
        $exception = new CaptureException('Capture not possible.');
        $exception->reason = $reason;
        $this->captureService->method('doCapture')->willThrowException($exception);
    }
}
