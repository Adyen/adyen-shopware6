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

namespace Adyen\Shopware\Unit;

use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Entity\Refund\RefundEntityCollection;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Util\Currency;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

class RefundServiceTest extends TestCase
{
    /** @var \PHPUnit\Framework\MockObject\MockObject|Adyen\Service\Shopware\RefundService  */
    private $refundService;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testIsAmountRefundableNoRefunds()
    {
        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection([]));
        $order = $this->createThrowAwayOrder('1', 100.01);

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
        $refund = $this->createThrowAwayRefund('1', 10001, RefundEntity::STATUS_SUCCESS);
        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection([$refund]));
        $order = $this->createThrowAwayOrder('1', 100.01);

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
        $refunds = [
            $this->createThrowAwayRefund('1', 22233, RefundEntity::STATUS_SUCCESS),
            $this->createThrowAwayRefund('2', 11100, RefundEntity::STATUS_FAILED),
            $this->createThrowAwayRefund('3', 5200, RefundEntity::STATUS_SUCCESS)
        ];

        $refundRepository = $this->getSimpleMock(AdyenRefundRepository::class);
        $refundRepository->method('getRefundsByOrderId')->willReturn(new EntityCollection($refunds));
        $order = $this->createThrowAwayOrder('1', 333.33);

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

    /**
     * @param $originalClassName
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function getSimpleMock($originalClassName)
    {
        return $this->getMockBuilder($originalClassName)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @param string $id
     * @param float $amountTotal
     * @return OrderEntity
     */
    private function createThrowAwayOrder(string $id, float $amountTotal): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($id);
        $order->setAmountTotal($amountTotal);

        return $order;
    }

    /**
     * @param string $id
     * @param int $amount
     * @param string $status
     * @return RefundEntity
     */
    private function createThrowAwayRefund(string $id, int $amount, string $status): RefundEntity
    {
        $refund = new RefundEntity();
        $refund->setId($id);
        $refund->setAmount($amount);
        $refund->setStatus($status);

        return $refund;
    }
}
