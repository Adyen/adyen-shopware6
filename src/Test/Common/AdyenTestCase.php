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

namespace Adyen\Shopware\Test\Common;

use Adyen\Shopware\Entity\Refund\RefundEntity;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;

abstract class AdyenTestCase extends TestCase
{
    /**
     * @param $originalClassName
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function getSimpleMock($originalClassName)
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
    protected function createThrowAwayOrder(string $id, float $amountTotal): OrderEntity
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
    protected function createThrowAwayRefund(string $id, int $amount, string $status): RefundEntity
    {
        $refund = new RefundEntity();
        $refund->setId($id);
        $refund->setAmount($amount);
        $refund->setStatus($status);

        return $refund;
    }
}
