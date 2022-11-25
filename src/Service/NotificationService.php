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

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\ScheduledTask\ProcessNotificationsHandler;
use DateTimeInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class NotificationService
{
    /** @var EntityRepositoryInterface */
    protected $notificationRepository;

    public function __construct(EntityRepositoryInterface $notificationRepository)
    {
        $this->notificationRepository = $notificationRepository;
    }

    public function getNumberOfUnprocessedNotifications(): int
    {
        return $this->notificationRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('processing', 0)),
            Context::createDefaultContext()
        )->count();
    }

    public function isDuplicateNotification(array $notification): bool
    {
        $filters = [];
        if (!empty($notification['pspReference'])) {
            $filters[] = new EqualsFilter('pspreference', $notification['pspReference']);
        }
        if (!empty($notification['success'])) {
            $filters[] = new EqualsFilter('success', $notification['success']);
        }
        if (!empty($notification['eventCode'])) {
            $filters[] = new EqualsFilter('eventCode', $notification['eventCode']);
        }
        if (!empty($notification['originalReference'])) {
            $filters[] = new EqualsFilter('originalReference', $notification['originalReference']);
        }

        return $this->notificationRepository->search(
            (new Criteria())->addFilter(...$filters),
            Context::createDefaultContext()
        )->count() > 0;
    }

    public function insertNotification(array $notification): void
    {
        //TODO Handle the notification as a model object to avoid this?
        $fields = [];
        if (isset($notification['pspReference'])) {
            $fields['pspreference'] = $notification['pspReference'];
        }
        if (isset($notification['originalReference'])) {
            $fields['originalReference'] = $notification['originalReference'];
        }
        if (isset($notification['merchantReference'])) {
            $fields['merchantReference'] = $notification['merchantReference'];
        }
        if (isset($notification['eventCode'])) {
            $fields['eventCode'] = $notification['eventCode'];
        }
        if (isset($notification['success'])) {
            $fields['success'] = "true" === $notification['success'];
        }
        if (isset($notification['paymentMethod'])) {
            $fields['paymentMethod'] = $notification['paymentMethod'];
        }
        if (isset($notification['amount']['value'])) {
            $fields['amountValue'] = (string) $notification['amount']['value'];
        }
        if (isset($notification['amount']['currency'])) {
            $fields['amountCurrency'] = $notification['amount']['currency'];
        }
        if (isset($notification['reason'])) {
            $fields['reason'] = $notification['reason'];
        }
        if (isset($notification['live'])) {
            $fields['live'] = $notification['live'];
        }
        if (isset($notification['additionalData'])) {
            $fields['additionalData'] = json_encode($notification['additionalData']);
        }

        $this->notificationRepository->create(
            [$fields],
            Context::createDefaultContext()
        );
    }

    public function setNotificationSchedule(string $notificationId, DateTimeInterface $scheduledProcessingTime): void
    {
        $this->notificationRepository->update(
            [
                [
                    'id' => $notificationId,
                    'scheduledProcessingTime' => $scheduledProcessingTime
                ]
            ],
            Context::createDefaultContext()
        );
    }

    public function getNotificationById($notificationId)
    {
        return $this->notificationRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('id', $notificationId)),
            Context::createDefaultContext()
        )->first();
    }

    public function getAllNotificationsByOrderNumber(string $orderNumber): EntityCollection
    {
        return $this->notificationRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('merchantReference', $orderNumber)),
            Context::createDefaultContext()
        )->getEntities();
    }

    public function getUnscheduledNotifications(): EntityCollection
    {
        return $this->notificationRepository->search(
            (new Criteria())->addFilter(
                new EqualsFilter('done', 0),
                new EqualsFilter('processing', 0),
                new EqualsFilter('scheduledProcessingTime', null)
            )
            ->addSorting(new FieldSorting('createdAt'))
            ->setLimit(100),
            Context::createDefaultContext()
        )->getEntities();
    }

    public function getScheduledUnprocessedNotifications(): EntityCollection
    {
        $oneDayAgo = (new \DateTime())->sub(new \DateInterval('P1D'));
        $oneMinuteAgo = (new \DateTime())->sub(new \DateInterval('PT1M'));
        return $this->notificationRepository->search(
            (new Criteria())->addFilter(
                new EqualsFilter('done', 0),
                new EqualsFilter('processing', 0),
                new NotFilter(
                    NotFilter::CONNECTION_AND,
                    [ new EqualsFilter('scheduledProcessingTime', null) ]
                ),
                new RangeFilter(
                    'scheduledProcessingTime',
                    [
                        RangeFilter::GTE => $oneDayAgo->format('Y-m-d H:i:s'),
                        RangeFilter::LT => $oneMinuteAgo->format('Y-m-d H:i:s')
                    ]
                )
            )
            ->addSorting(new FieldSorting('scheduledProcessingTime', FieldSorting::ASCENDING))
            ->setLimit(100),
            Context::createDefaultContext()
        )->getEntities();
    }

    public function getSkippedUnprocessedNotifications(): EntityCollection
    {
        $oneDayAgo = (new \DateTime())->sub(new \DateInterval('P1D'));

        return $this->notificationRepository->search(
            (new Criteria())->addFilter(
                new EqualsFilter('done', 0),
                new NotFilter(
                    NotFilter::CONNECTION_AND,
                    [ new EqualsFilter('scheduledProcessingTime', null) ]
                ),
                new MultiFilter(
                    MultiFilter::CONNECTION_AND,
                    [
                        new RangeFilter(
                            'scheduledProcessingTime',
                            [
                                RangeFilter::LTE => $oneDayAgo->format('Y-m-d H:i:s')
                            ]
                        ),
                        new MultiFilter(
                            MultiFilter::CONNECTION_OR,
                            [
                                new RangeFilter(
                                    'errorCount',
                                    [
                                        RangeFilter::LT => ProcessNotificationsHandler::MAX_ERROR_COUNT
                                    ]
                                ),
                                new EqualsFilter('errorCount', null)
                            ]
                        )
                    ]
                )
            )
                ->addSorting(new FieldSorting('scheduledProcessingTime', FieldSorting::ASCENDING))
                ->setLimit(100),
            Context::createDefaultContext()
        )->getEntities();
    }

    public function changeNotificationState(string $notificationId, string $property, bool $state): void
    {
        $this->notificationRepository->update(
            [
                [
                    'id' => $notificationId,
                    $property => $state
                ]
            ],
            Context::createDefaultContext()
        );
    }

    public function saveError(string $notificationId, string $errorMessage, int $errorCount): void
    {
        $this->notificationRepository->update(
            [
                [
                    'id' => $notificationId,
                    'errorMessage' => $errorMessage,
                    'errorCount' => $errorCount,
                ]
            ],
            Context::createDefaultContext()
        );
    }

    /**
     * Calculates the scheduled processing time according to notification type.
     *
     * @param NotificationEntity $notification
     * @param bool $reschedule
     * @return \DateTimeInterface|null
     */
    public function calculateScheduledProcessingTime(NotificationEntity $notification, bool $reschedule = false)
    {
        if ($reschedule) {
            $scheduledProcessingTime = new \DateTime();
        } else {
            $scheduledProcessingTime = $notification->getCreatedAt();
        }

        switch ($notification->getEventCode()) {
            case 'AUTHORISATION':
                if (!$notification->isSuccess()) {
                    $scheduledProcessingTime = $scheduledProcessingTime->add(new \DateInterval('PT30M'));
                }
                break;
            case 'OFFER_CLOSED':
            case 'ORDER_CLOSED':
                $scheduledProcessingTime = $scheduledProcessingTime->add(new \DateInterval('PT30M'));
                break;
            default:
                break;
        }

        return $scheduledProcessingTime;
    }

    /**
     * @param NotificationEntity $notification
     * @return bool
     */
    public function canBeRescheduled(NotificationEntity $notification): bool
    {
        if (!is_null($notification->getScheduledProcessingTime())) {
            $timeDifferenceInDays = $notification->getScheduledProcessingTime()
                ->diff(new \DateTime())->format('%a');

            if (!$notification->isDone() && $timeDifferenceInDays >= 1) {
                return true;
            }
        }

        return false;
    }
}
