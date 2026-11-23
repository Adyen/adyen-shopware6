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
 * Copyright (c) 2026 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Test\Unit;

use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Test\Common\AdyenTestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class OrderTransactionRepositoryTest extends AdyenTestCase
{
    private const STATES = [OrderTransactionStates::STATE_AUTHORIZED, OrderTransactionStates::STATE_PAID];

    private ?Criteria $criteria = null;
    private ?Context $context = null;
    private OrderTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $entityRepository = $this->getSimpleMock(EntityRepository::class);
        $entityRepository->method('search')->willReturnCallback(
            function (Criteria $criteria, Context $context): EntitySearchResult {
                $this->criteria = $criteria;
                $this->context = $context;

                return new EntitySearchResult(
                    'order_transaction',
                    0,
                    new OrderTransactionCollection(),
                    null,
                    $criteria,
                    $context
                );
            }
        );

        $this->repository = new OrderTransactionRepository($entityRepository);
    }

    public function testAllAdyenTransactionsByStates(): void
    {
        $this->repository->getAdyenOrderTransactionsByStates('order-1', self::STATES);

        $this->assertSharedCriteria();
        self::assertNull($this->criteria->getLimit());
        self::assertCount(3, $this->criteria->getFilters());
    }

    public function testFirstAdyenTransactionByStates(): void
    {
        $this->repository->getFirstAdyenOrderTransactionByStates('order-1', self::STATES);

        $this->assertSharedCriteria();
        self::assertSame(1, $this->criteria->getLimit());
        self::assertCount(3, $this->criteria->getFilters());
    }

    public function testFirstAdyenTransactionOfTheContextCustomer(): void
    {
        $context = Context::createDefaultContext();
        $salesChannelContext = $this->getSimpleMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn('sales-channel-1');
        $salesChannelContext->method('getCustomerId')->willReturn('customer-1');
        $salesChannelContext->method('getContext')->willReturn($context);

        $this->repository->getFirstAdyenOrderTransactionByStates('order-1', self::STATES, $salesChannelContext);

        $this->assertSharedCriteria();
        self::assertSame(1, $this->criteria->getLimit());
        self::assertSame($context, $this->context);
        $filters = $this->getFilterValues();
        self::assertSame('sales-channel-1', $filters['order.salesChannelId']);
        self::assertSame('customer-1', $filters['order.orderCustomer.customerId']);
        self::assertCount(5, $this->criteria->getFilters());
    }

    private function assertSharedCriteria(): void
    {
        self::assertNotNull($this->criteria);
        foreach (['stateMachineState', 'order', 'paymentMethod'] as $association) {
            self::assertTrue($this->criteria->hasAssociation($association), $association);
        }
        self::assertTrue($this->criteria->getAssociation('order')->hasAssociation('currency'));
        self::assertTrue($this->criteria->getAssociation('paymentMethod')->hasAssociation('plugin'));

        $filters = $this->getFilterValues();
        self::assertSame('order-1', $filters['order.id']);
        self::assertSame(self::STATES, $filters['stateMachineState.technicalName']);
        self::assertSame('AdyenPaymentShopware6', $filters['paymentMethod.plugin.name']);

        $sortings = $this->criteria->getSorting();
        self::assertCount(1, $sortings);
        self::assertSame('createdAt', $sortings[0]->getField());
        self::assertSame(FieldSorting::DESCENDING, $sortings[0]->getDirection());
    }

    /**
     * @return array<string, mixed> Value of every equals / equals any filter by field.
     */
    private function getFilterValues(): array
    {
        $values = [];
        foreach ($this->criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter || $filter instanceof EqualsAnyFilter) {
                $values[$filter->getField()] = $filter->getValue();
            }
        }

        return $values;
    }
}
