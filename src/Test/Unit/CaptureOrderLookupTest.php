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

use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureConfigurationResolver;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ClientService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\Repository\AdyenPaymentCaptureRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Shopware\Core\Framework\Context;

/**
 * Sales channels with their own number ranges can have orders with the same order number, so the capture loads
 * the order by its ID when the caller knows it.
 */
class CaptureOrderLookupTest extends AdyenTestCase
{
    public function testOrderIsLoadedByIdWhenGiven(): void
    {
        $orderRepository = $this->getSimpleMock(OrderRepository::class);
        $orderRepository->expects($this->once())->method('getOrder')
            ->with('order-2', $this->anything(), $this->anything())
            ->willReturn(null);
        $orderRepository->expects($this->never())->method('getOrderByOrderNumber');

        $this->expectException(CaptureException::class);

        $this->createService($orderRepository)
            ->doCapture('10001', 1000, Context::createDefaultContext(), false, 'order-2');
    }

    public function testOrderIsLoadedByOrderNumberWithoutId(): void
    {
        $orderRepository = $this->getSimpleMock(OrderRepository::class);
        $orderRepository->expects($this->once())->method('getOrderByOrderNumber')
            ->with('10001', $this->anything(), $this->anything())
            ->willReturn(null);
        $orderRepository->expects($this->never())->method('getOrder');

        $this->expectException(CaptureException::class);

        $this->createService($orderRepository)->doCapture('10001', 1000, Context::createDefaultContext());
    }

    private function createService(OrderRepository $orderRepository): CaptureService
    {
        return new CaptureService(
            $orderRepository,
            $this->getSimpleMock(OrderTransactionRepository::class),
            $this->getSimpleMock(AdyenPaymentCaptureRepository::class),
            $this->getSimpleMock(ConfigurationService::class),
            $this->getSimpleMock(ClientService::class),
            $this->getSimpleMock(AdyenPaymentService::class),
            $this->getSimpleMock(CaptureConfigurationResolver::class)
        );
    }
}
