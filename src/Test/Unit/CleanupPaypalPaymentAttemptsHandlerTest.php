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

use Adyen\Shopware\ScheduledTask\CleanupPaypalPaymentAttemptsHandler;
use Adyen\Shopware\Service\Repository\PaypalPaymentAttemptRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class CleanupPaypalPaymentAttemptsHandlerTest extends AdyenTestCase
{
    public function testRunDeletesAttemptsOlderThanTheRetentionPeriod(): void
    {
        $repository = $this->getSimpleMock(PaypalPaymentAttemptRepository::class);
        $repository->expects($this->once())
            ->method('deleteCreatedBefore')
            ->with($this->callback(function (\DateTimeInterface $createdBefore) {
                $expected = (new \DateTimeImmutable())->sub(new \DateInterval('P30D'));

                return abs($expected->getTimestamp() - $createdBefore->getTimestamp()) < 5;
            }))
            ->willReturn(3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $handler = new CleanupPaypalPaymentAttemptsHandler(
            $this->getSimpleMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $repository
        );
        $handler->setLogger($logger);

        $handler->run();
    }

    public function testRunLogsNothingWhenNothingWasDeleted(): void
    {
        $repository = $this->getSimpleMock(PaypalPaymentAttemptRepository::class);
        $repository->method('deleteCreatedBefore')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $handler = new CleanupPaypalPaymentAttemptsHandler(
            $this->getSimpleMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $repository
        );
        $handler->setLogger($logger);

        $handler->run();
    }
}
