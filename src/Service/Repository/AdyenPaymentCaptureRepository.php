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
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class AdyenPaymentCaptureRepository
{
    /**
     * @var EntityRepositoryInterface
     */
    private $repository;

    /**
     * AdyenPaymentCaptureRepository constructor.
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
     * Get all captures linked to an order, based on the order id
     *
     * @param string $orderId
     * @return EntityCollection
     */
    public function getCaptureRequestsByOrderId(string $orderId): EntityCollection
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
