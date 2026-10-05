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

namespace Adyen\Shopware\ScheduledTask;

use Adyen\Shopware\Service\Repository\PaypalPaymentAttemptRepository;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

/**
 * Removes PayPal payment attempts that can no longer be needed by the webhook fallback: abandoned PayPal popups
 * that were never approved, and attempts whose reversal is already recorded.
 */
class CleanupPaypalPaymentAttemptsHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    /**
     * Well beyond the period in which Adyen retries an AUTHORISATION webhook, and long enough to look up a
     * reversal for manual follow-up.
     */
    public const RETENTION_PERIOD = 'P30D';

    /**
     * @var PaypalPaymentAttemptRepository
     */
    private PaypalPaymentAttemptRepository $paypalPaymentAttemptRepository;

    /**
     * @param EntityRepository $scheduledTaskRepository
     * @param PaypalPaymentAttemptRepository $paypalPaymentAttemptRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        PaypalPaymentAttemptRepository $paypalPaymentAttemptRepository
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->paypalPaymentAttemptRepository = $paypalPaymentAttemptRepository;
    }

    /**
     * @return iterable
     */
    public static function getHandledMessages(): iterable
    {
        return [CleanupPaypalPaymentAttempts::class];
    }

    /**
     * @return void
     */
    public function run(): void
    {
        $createdBefore = (new \DateTimeImmutable())->sub(new \DateInterval(self::RETENTION_PERIOD));
        $deleted = $this->paypalPaymentAttemptRepository->deleteCreatedBefore($createdBefore);

        if ($deleted > 0) {
            $this->logger->info(sprintf(
                'Removed %d PayPal payment attempts created before %s.',
                $deleted,
                $createdBefore->format(\DateTimeInterface::ATOM)
            ));
        }
    }
}
