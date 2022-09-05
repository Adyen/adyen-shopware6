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

namespace Adyen\Shopware\Handlers;

use Adyen\Shopware\Entity\Notification\NotificationEntity;

class NotificationHandler
{
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
        $timeDifferenceInDays = $notification->getScheduledProcessingTime()->diff(new \DateTime())->format('%a');

        if (!$notification->isDone() && $timeDifferenceInDays >= 1) {
            return true;
        } else {
            return false;
        }
    }
}
