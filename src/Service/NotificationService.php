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

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class NotificationService
{
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
        if (!empty($notification['pspreference'])) {
            $fields['pspreference'] = $notification['pspreference'];
        }
        if (!empty($notification['original_reference'])) {
            $fields['original_reference'] = $notification['original_reference'];
        }
        if (!empty($notification['merchant_reference'])) {
            $fields['merchant_reference'] = $notification['merchant_reference'];
        }
        if (!empty($notification['event_code'])) {
            $fields['event_code'] = $notification['event_code'];
        }
        if (!empty($notification['success'])) {
            $fields['success'] = (bool)$notification['success'];
        }
        if (!empty($notification['payment_method'])) {
            $fields['payment_method'] = $notification['payment_method'];
        }
        if (!empty($notification['amount_value'])) {
            $fields['amount_value'] = $notification['amount_value'];
        }
        if (!empty($notification['amount_currency'])) {
            $fields['amount_currency'] = $notification['amount_currency'];
        }
        if (!empty($notification['reason'])) {
            $fields['reason'] = $notification['reason'];
        }
        if (!empty($notification['live'])) {
            $fields['live'] = $notification['live'];
        }
        if (!empty($notification['additional_data'])) {
            $fields['additional_data'] = $notification['additional_data'];
        }

        $this->notificationRepository->create([$fields],
            \Shopware\Core\Framework\Context::createDefaultContext()
        );
    }
}
