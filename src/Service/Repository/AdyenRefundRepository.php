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

use Adyen\Shopware\Entity\Refund\RefundEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class AdyenRefundRepository
{
    /**
     * @var EntityRepository
     */
    private $repository;

    /**
     * AdyenRefundRepository constructor.
     *
     * @param EntityRepository $repository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $repository
    ) {
        $this->repository = $repository;
    }

    /**
     * Get all refunds linked to an order, based on the order id
     *
     * @param string $orderId
     * @return EntityCollection
     */
    public function getRefundsByOrderId(string $orderId): EntityCollection
    {
        $criteria = new Criteria();
        $criteria->addAssociation('orderTransaction');
        $criteria->addAssociation('orderTransaction.order');
        $criteria->addAssociation('orderTransaction.order.currency');
        $criteria->addFilter(new EqualsFilter('orderTransaction.order.id', $orderId));

        return $this->repository->search($criteria, Context::createDefaultContext());
    }

    /**
     * Filtering with pspReference and orderTransactionId since multiple refunds are possible
     * @param string $orderTransactionId
     * @param string $pspReference
     * @return RefundEntity|null
     */
    public function getRefundForOrderByPspReference(string $orderTransactionId, string $pspReference): ?RefundEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new AndFilter([
            new EqualsFilter('orderTransactionId', $orderTransactionId),
            new EqualsFilter('pspReference', $pspReference)
        ]));

        return $this->repository->search($criteria, Context::createDefaultContext())->first();
    }

    /**
     * @return EntityRepository
     */
    public function getRepository() : EntityRepository
    {
        return $this->repository;
    }
}
