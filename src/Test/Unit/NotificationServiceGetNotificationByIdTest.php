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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\Notification\NotificationEntityCollection;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * The admin "Reschedule notification" action loads the notification by its ID.
 */
class NotificationServiceGetNotificationByIdTest extends AdyenTestCase
{
    public function testReturnsTheNotification(): void
    {
        $notification = new NotificationEntity();
        $notification->setId('notification-1');

        $this->assertSame($notification, $this->createService([$notification])->getNotificationById('notification-1'));
    }

    public function testReturnsNullForAnUnknownId(): void
    {
        $this->assertNull($this->createService([])->getNotificationById('unknown'));
    }

    private function createService(array $notifications): NotificationService
    {
        $repository = $this->getSimpleMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            fn (Criteria $criteria, Context $context) => new EntitySearchResult(
                'adyen_notification',
                count($notifications),
                new NotificationEntityCollection($notifications),
                new AggregationResultCollection(),
                $criteria,
                $context
            )
        );

        return new NotificationService($repository);
    }
}
