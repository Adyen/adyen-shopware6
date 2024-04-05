<?php
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

use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Adyen\Util\Currency;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class RefundServiceTest extends AdyenTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testIsAmountRefundableNoRefunds()
    {
        $order = $this->createThrowAwayOrder('1', 100.01);
        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection([]));

        $refundService = $this->createMockedRefundService([
            AdyenRefundRepository::class => $refundRepository,
            Currency::class => new Currency()
        ]);

        $this->assertTrue($refundService->isAmountRefundable($order, '5000'));
    }

    public function testIsAmountRefundableFullRefundFalse()
    {
        $order = $this->createThrowAwayOrder('1', 100.01);
        $refund = $this->createThrowAwayRefund('1', 10001, RefundEntity::STATUS_SUCCESS);
        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection([$refund]));

        $refundService = $this->createMockedRefundService([
            AdyenRefundRepository::class => $refundRepository,
            Currency::class => new Currency()
        ]);

        $this->assertFalse($refundService->isAmountRefundable($order, '1'));
    }

    public function testIsAmountRefundablePartialRefundsTrue()
    {
        $order = $this->createThrowAwayOrder('1', 333.33);
        $refunds = [
            $this->createThrowAwayRefund('1', 22233, RefundEntity::STATUS_SUCCESS),
            $this->createThrowAwayRefund('2', 11100, RefundEntity::STATUS_FAILED),
            $this->createThrowAwayRefund('3', 5200, RefundEntity::STATUS_SUCCESS)
        ];

        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection($refunds));

        $refundService = $this->createMockedRefundService([
            AdyenRefundRepository::class => $refundRepository,
            Currency::class => new Currency()
        ]);

        $this->assertTrue($refundService->isAmountRefundable($order, '5900'));
    }

    /**
     * Use default mocks to create RefundService, except for what is passed in the array
     * Not using a for loop to ensure better readability
     *
     * @param array $parameters
     * @return RefundService
     */
    private function createMockedRefundService(array $classArguments) : RefundService
    {
        $logger = $this->getSimpleMock(Logger::class);
        $configService = $this->getSimpleMock(ConfigurationService::class);
        $clientService = $this->getSimpleMock(ClientService::class);
        $refundRepo = $this->getSimpleMock(AdyenRefundRepository::class);
        $currency = $this->getSimpleMock(Currency::class);
        $orderTransactionRepo = $this->getSimpleMock(OrderTransactionRepository::class);
        $orderTransactionStateHandler = $this->getSimpleMock(OrderTransactionStateHandler::class);


        if (array_key_exists(Logger::class, $classArguments)) {
            $logger = $classArguments[Logger::class];
        }

        if (array_key_exists(ConfigurationService::class, $classArguments)) {
            $configService = $classArguments[ConfigurationService::class];
        }

        if (array_key_exists(ClientService::class, $classArguments)) {
            $clientService = $classArguments[ClientService::class];
        }

        if (array_key_exists(AdyenRefundRepository::class, $classArguments)) {
            $refundRepo = $classArguments[AdyenRefundRepository::class];
        }

        if (array_key_exists(Currency::class, $classArguments)) {
            $currency = $classArguments[Currency::class];
        }

        if (array_key_exists(OrderTransactionRepository::class, $classArguments)) {
            $orderTransactionRepo = $classArguments[OrderTransactionRepository::class];
        }

        if (array_key_exists(OrderTransactionStateHandler::class, $classArguments)) {
            $orderTransactionStateHandler = $classArguments[OrderTransactionStateHandler::class];
        }

        return new RefundService(
            $logger,
            $configService,
            $clientService,
            $refundRepo,
            $currency,
            $orderTransactionRepo,
            $orderTransactionStateHandler
        );
    }
}
