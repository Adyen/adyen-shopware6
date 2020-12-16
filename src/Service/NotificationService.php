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
        if (array_key_exists('pspReference', $notification)) {
            $fields['pspreference'] = $notification['pspReference'];
        }
        if (array_key_exists('originalReference', $notification)) {
            $fields['originalReference'] = $notification['originalReference'];
        }
        if (array_key_exists('merchantReference', $notification)) {
            $fields['merchantReference'] = $notification['merchantReference'];
        }
        if (array_key_exists('eventCode', $notification)) {
            $fields['eventCode'] = $notification['eventCode'];
        }
        if (array_key_exists('success', $notification)) {
            $fields['success'] = "true" === $notification['success'];
        }
        if (array_key_exists('paymentMethod', $notification)) {
            $fields['paymentMethod'] = $notification['paymentMethod'];
        }
        if (array_key_exists('value', $notification['amount'] ?? [])) {
            $fields['amountValue'] = (string) $notification['amount']['value'];
        }
        if (array_key_exists('currency', $notification['amount'] ?? [])) {
            $fields['amountCurrency'] = $notification['amount']['currency'];
        }
        if (array_key_exists('reason', $notification)) {
            $fields['reason'] = $notification['reason'];
        }
        if (array_key_exists('live', $notification)) {
            $fields['live'] = $notification['live'];
        }
        if (array_key_exists('additionalData', $notification)) {
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
