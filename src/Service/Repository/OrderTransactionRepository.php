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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class OrderTransactionRepository
{
    /**
     * @var EntityRepository
     */
    private $repository;

    /**
     * OrderTransactionRepository constructor.
     *
     * @param EntityRepository $repository
     */
    public function __construct(EntityRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param string $orderId
     * @param array $states
     * @return OrderTransactionEntity|null
     */
    public function getFirstAdyenOrderTransactionByStates(
        string $orderId,
        array $states
    ): ?OrderTransactionEntity {
        $criteria = new Criteria();
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('paymentMethod.plugin');
        $criteria->addFilter(new EqualsFilter('order.id', $orderId));
        $criteria->addFilter(
            new EqualsAnyFilter('stateMachineState.technicalName', $states)
        );
        $criteria->addFilter(
            new EqualsFilter('paymentMethod.plugin.name', ConfigurationService::BUNDLE_NAME)
        );

        $criteria->setLimit(1);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        return $this->repository->search($criteria, Context::createDefaultContext())->first();
    }

    /**
     * @param string $orderId
     * @return OrderTransactionEntity|null
     */
    public function getFirstAdyenOrderTransaction(string $orderId): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('paymentMethod.plugin');
        $criteria->addFilter(new EqualsFilter('order.id', $orderId));
        $criteria->addFilter(
            new EqualsFilter('paymentMethod.plugin.name', ConfigurationService::BUNDLE_NAME)
        );

        $criteria->setLimit(1);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        return $this->repository->search($criteria, Context::createDefaultContext())->first();
    }

    /**
     * @param string $orderTransactionId
     * @return OrderTransactionEntity|null
     */
    public function getWithId(string $orderTransactionId): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('paymentMethod.plugin');
        $criteria->addFilter(new EqualsFilter('id', $orderTransactionId));

        return $this->repository->search($criteria, Context::createDefaultContext())->first();
    }

    /**
     * @param OrderTransactionEntity $orderTransactionEntity
     *
     * @return void
     *
     * @throws \JsonException
     */
    public function updateCustomFields(OrderTransactionEntity $orderTransactionEntity): void
    {
        $this->repository->update([[
            'id' => $orderTransactionEntity->getId(),
            'customFields' => $orderTransactionEntity->getCustomFields(),
        ]], Context::createDefaultContext());
    }
}
