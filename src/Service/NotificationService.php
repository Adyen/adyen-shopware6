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
            (new Criteria())->addFilter(new EqualsFilter('processed', 0)),
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
                (new Criteria())->addFilter($filters),
                Context::createDefaultContext()
            )->count() > 0;
    }

    public function insertNotification(array $notification): void
    {

        $fields = [
            'pspreference' => $notification['pspReference'],
            'original_reference' => $notification['originalReference'],
            'merchant_reference' => $notification['merchantReference'],
            'event_code' => $notification['eventCode'],
            'success' => $notification['success'],
            'payment_method' => $notification['paymentMethod'],
            'amount_value' => $notification['amountValue'],
            'amount_currency' => $notification['amountCurrency'],
            'reason' => $notification['reason'],
            'live' => $notification['live'],
            'additional_data' => $notification['additionalData']
        ];

        $this->notificationRepository->create([$fields],
            \Shopware\Core\Framework\Context::createDefaultContext()
        );
    }
}
