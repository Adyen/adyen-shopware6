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

use DateTimeInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
}
