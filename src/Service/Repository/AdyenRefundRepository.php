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

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class AdyenRefundRepository
{
    /**
     * @var EntityRepositoryInterface
     */
    private $repository;

    /**
     * AdyenRefundRepository constructor.
     *
     * @param EntityRepositoryInterface $repository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepositoryInterface $repository
    ) {
        $this->repository = $repository;
    }

    /**
     * Get all refunds linked to an order, based on the order number
     *
     * @param string $orderId
     * @return EntitySearchResult
     */
    public function getRefundsByOrderId(string $orderId): EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->addAssociation('orderTransaction');
        $criteria->addAssociation('orderTransaction.order');
        $criteria->addAssociation('orderTransaction.order.currency');
        $criteria->addFilter(new EqualsFilter('orderTransaction.order.id', $orderId));

        return $this->repository->search($criteria, Context::createDefaultContext());
    }

    /**
     * @return EntityRepositoryInterface
     */
    public function getRepository() : EntityRepositoryInterface
    {
        return $this->repository;
    }
}
