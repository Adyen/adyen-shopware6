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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\ScheduledTask;

use Adyen\Shopware\Service\NotificationService;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

class ScheduleNotificationsHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    /**
     * @var NotificationService
     */
    private $notificationService;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        NotificationService $notificationService
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->notificationService = $notificationService;
    }

    public static function getHandledMessages(): iterable
    {
        return [ ScheduleNotifications::class ];
    }

    public function run(): void
    {
        $unscheduledNotifications = $this->notificationService->getUnscheduledNotifications();

        if ($unscheduledNotifications->count() == 0) {
            $this->logger->debug("No unscheduled notifications found.");
        }

        foreach ($unscheduledNotifications->getElements() as $notification) {
            $scheduledProcessingTime = $this->notificationService->calculateScheduledProcessingTime($notification);
            $this->notificationService->setNotificationSchedule($notification->getId(), $scheduledProcessingTime);
        }

        if ($unscheduledNotifications->count() > 0) {
            $this->logger->info('Scheduled ' . $unscheduledNotifications->count() . ' notifications.');
        }

        // Reschedule the unprocessed notifications older than 24 hours
        $skippedNotifications = $this->notificationService->getSkippedUnprocessedNotifications();

        foreach ($skippedNotifications->getElements() as $notification) {
            $scheduledProcessingTime = $this->notificationService->calculateScheduledProcessingTime(
                $notification,
                true
            );
            // If notification was stuck in state Processing=true, reset the state and reschedule.
            if ($notification->getProcessing()) {
                $this->notificationService->changeNotificationState(
                    $notification->getId(),
                    'processing',
                    false
                );
            }
            $this->notificationService->setNotificationSchedule($notification->getId(), $scheduledProcessingTime);
        }

        if ($skippedNotifications->count() > 0) {
            $this->logger->info('Re-scheduled ' . $skippedNotifications->count() . ' skipped notifications.');
        }
    }
}
