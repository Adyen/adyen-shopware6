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

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Service\NotificationService;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

class ScheduleNotificationsHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    /**
     * @var NotificationService
     */
    private $notificationService;

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
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
            return;
        }

        foreach ($unscheduledNotifications->getElements() as $notification) {
            /** @var NotificationEntity $notification */

            $scheduledProcessingTime = $notification->getCreatedAt();
            switch ($notification->getEventCode()) {
                case 'AUTHORISATION':
                    if (!$notification->isSuccess()) {
                        $scheduledProcessingTime = $scheduledProcessingTime->add(new \DateInterval('PT30M'));
                    }
                    break;
                case 'OFFER_CLOSED':
                    $scheduledProcessingTime = $scheduledProcessingTime->add(new \DateInterval('PT30M'));
                    break;
                default:
                    break;
            }

            $this->notificationService->setNotificationSchedule($notification->getId(), $scheduledProcessingTime);
        }
    }
}
