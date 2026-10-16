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

namespace Adyen\Shopware\Models;

/**
 * Tells the notification processor what to do with a notification, without the caller knowing how notifications
 * are scheduled or marked as done.
 *
 * @package Adyen\Shopware\Models
 */
class NotificationProcessingResult
{
    public const ACTION_DONE = 'done';
    public const ACTION_RESCHEDULE = 'reschedule';
    public const ACTION_RETRY = 'retry';

    /**
     * @param string $action
     * @param string|null $error
     * @param \DateTime|null $scheduledProcessingTime
     */
    private function __construct(
        private readonly string $action,
        private readonly ?string $error = null,
        private readonly ?\DateTime $scheduledProcessingTime = null
    ) {
    }

    /**
     * The notification is handled. An error is recorded on the notification before it is marked as done.
     *
     * @param string|null $error
     *
     * @return self
     */
    public static function done(?string $error = null): self
    {
        return new self(self::ACTION_DONE, $error);
    }

    /**
     * The notification is processed again at the given time, without counting as a failure.
     *
     * @param \DateTime $scheduledProcessingTime
     *
     * @return self
     */
    public static function reschedule(\DateTime $scheduledProcessingTime): self
    {
        return new self(self::ACTION_RESCHEDULE, null, $scheduledProcessingTime);
    }

    /**
     * The notification failed and is processed again until the maximum error count is reached.
     *
     * @param string $error
     *
     * @return self
     */
    public static function retry(string $error): self
    {
        return new self(self::ACTION_RETRY, $error);
    }

    /**
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return \DateTime|null
     */
    public function getScheduledProcessingTime(): ?\DateTime
    {
        return $this->scheduledProcessingTime;
    }
}
