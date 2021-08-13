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

        $refundService = new RefundService(
            $this->getSimpleMock(Logger::class),
            $this->getSimpleMock(ConfigurationService::class),
            $this->getSimpleMock(ClientService::class),
            $refundRepository,
            new Currency(),
            $this->getSimpleMock(OrderTransactionRepository::class),
            $this->getSimpleMock(OrderTransactionStateHandler::class),
        );

        $this->assertTrue($refundService->isAmountRefundable($order, '5000'));
    }

    public function testIsAmountRefundableFullRefundFalse()
    {
        $order = $this->createThrowAwayOrder('1', 100.01);
        $refund = $this->createThrowAwayRefund('1', 10001, RefundEntity::STATUS_SUCCESS);
        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection([$refund]));

        $refundService = new RefundService(
            $this->getSimpleMock(Logger::class),
            $this->getSimpleMock(ConfigurationService::class),
            $this->getSimpleMock(ClientService::class),
            $refundRepository,
            new Currency(),
            $this->getSimpleMock(OrderTransactionRepository::class),
            $this->getSimpleMock(OrderTransactionStateHandler::class),
        );

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

        $refundService = new RefundService(
            $this->getSimpleMock(Logger::class),
            $this->getSimpleMock(ConfigurationService::class),
            $this->getSimpleMock(ClientService::class),
            $refundRepository,
            new Currency(),
            $this->getSimpleMock(OrderTransactionRepository::class),
            $this->getSimpleMock(OrderTransactionStateHandler::class),
        );

        $this->assertTrue($refundService->isAmountRefundable($order, '5900'));
    }
}
