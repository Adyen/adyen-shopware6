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

namespace Adyen\Shopware\Service\Repository;

use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\RefundService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class OrderTransactionRepository
{
    /**
     * @var EntityRepositoryInterface
     */
    private $repository;

    /**
     * OrderTransactionRepository constructor.
     *
     * @param EntityRepositoryInterface $repository
     */
    public function __construct(EntityRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param string $orderId
     * @param array $statesFilter
     * @return OrderTransactionEntity|null
     */
    public function getFirstAdyenOrderTransaction(
        string $orderId,
        array $statesFilter = []
    ): ?OrderTransactionEntity {
        $criteria = new Criteria();
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('paymentMethod.plugin');
        $criteria->addFilter(new EqualsFilter('order.id', $orderId));
        if (!empty($statesFilter)) {
            $criteria->addFilter(
                new EqualsAnyFilter('stateMachineState.technicalName', $statesFilter)
            );
        }
        $criteria->addFilter(
            new EqualsFilter('paymentMethod.plugin.name', ConfigurationService::BUNDLE_NAME)
        );

        $criteria->setLimit(1);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));

        return $this->repository->search($criteria, Context::createDefaultContext())->first();
    }

    /**
     * @param string $orderId
     * @param array $statesFilter
     * @return OrderTransactionEntity|null
     */
    public function getRecentAdyenOrderTransaction(
        string $orderId,
        array $statesFilter = []
    ): ?OrderTransactionEntity {
        $criteria = new Criteria();
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('paymentMethod.plugin');
        $criteria->addFilter(new EqualsFilter('order.id', $orderId));
        if (!empty($statesFilter)) {
            $criteria->addFilter(
                new EqualsAnyFilter('stateMachineState.technicalName', $statesFilter)
            );
        }
        $criteria->addFilter(
            new EqualsFilter('paymentMethod.plugin.name', ConfigurationService::BUNDLE_NAME)
        );

        $criteria->setLimit(1);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        return $this->repository->search($criteria, Context::createDefaultContext())->first();
    }
}
