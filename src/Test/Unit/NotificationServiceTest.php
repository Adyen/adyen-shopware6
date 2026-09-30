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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class NotificationServiceTest extends AdyenTestCase
{
    public function testMissingOrderGracePeriodEndWhileTheGracePeriodRuns(): void
    {
        $createdAt = new \DateTimeImmutable('-10 minutes');
        $notification = new NotificationEntity();
        $notification->setCreatedAt($createdAt);

        $gracePeriodEnd = $this->createNotificationService()->getMissingOrderGracePeriodEnd($notification);

        $this->assertEquals($createdAt->add(new \DateInterval('PT30M')), $gracePeriodEnd);
    }

    public function testMissingOrderGracePeriodEndIsNullOnceTheGracePeriodIsOver(): void
    {
        $notification = new NotificationEntity();
        $notification->setCreatedAt(new \DateTimeImmutable('-31 minutes'));

        $this->assertNull($this->createNotificationService()->getMissingOrderGracePeriodEnd($notification));
    }

    private function createNotificationService(): NotificationService
    {
        return new NotificationService($this->getSimpleMock(EntityRepository::class));
    }
}
