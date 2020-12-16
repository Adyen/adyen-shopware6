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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

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
        if (!empty($notification['pspreference'])) {
            $filters[] = new EqualsFilter('pspReference', $notification['pspreference']);
        }
        if (!empty($notification['success'])) {
            $filters[] = new EqualsFilter('success', $notification['success']);
        }
        if (!empty($notification['event_code'])) {
            $filters[] = new EqualsFilter('eventCode', $notification['event_code']);
        }
        if (!empty($notification['original_reference'])) {
            $filters[] = new EqualsFilter('originalReference', $notification['original_reference']);
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
        if (!empty($notification['pspReference'])) {
            $fields['pspreference'] = $notification['pspReference'];
        }
        if (!empty($notification['originalReference'])) {
            $fields['originalReference'] = $notification['originalReference'];
        }
        if (!empty($notification['merchantReference'])) {
            $fields['merchantReference'] = $notification['merchantReference'];
        }
        if (!empty($notification['eventCode'])) {
            $fields['eventCode'] = $notification['eventCode'];
        }
        if (!empty($notification['success'])) {
            $fields['success'] = "true" === $notification['success'];
        }
        if (!empty($notification['paymentMethod'])) {
            $fields['paymentMethod'] = $notification['paymentMethod'];
        }
        if (!empty($notification['amount']['value'])) {
            $fields['amountValue'] = (string)$notification['amount']['value'];
        }
        if (!empty($notification['amount']['currency'])) {
            $fields['amountCurrency'] = $notification['amount']['currency'];
        }
        if (!empty($notification['reason'])) {
            $fields['reason'] = $notification['reason'];
        }
        if (!empty($notification['live'])) {
            $fields['live'] = "true" === $notification['live'];
        }
        if (!empty($notification['additionalData'])) {
            $fields['additionalData'] = json_encode($notification['additionalData']);
        }

        $this->notificationRepository->create(
            [$fields],
            Context::createDefaultContext()
        );
    }

    public function setNotificationSchedule(string $notificationId, DateTimeInterface $scheduledProcessingTime)
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
}
