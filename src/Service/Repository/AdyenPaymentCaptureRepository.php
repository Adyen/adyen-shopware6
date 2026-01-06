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

use Adyen\Shopware\Entity\PaymentCapture\PaymentCaptureEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class AdyenPaymentCaptureRepository
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $repository;

    /**
     * AdyenPaymentCaptureRepository constructor.
     *
     * @param EntityRepository $repository
     */
    public function __construct(
        EntityRepository $repository
    ) {
        $this->repository = $repository;
    }

    /**
     * Get all captures linked to an order, based on the order id
     *
     * @param string $orderId
     * @param bool|null $isOnlySuccess
     *
     * @return EntityCollection
     */
    public function getCaptureRequestsByOrderId(string $orderId, ?bool $isOnlySuccess = null): EntityCollection
    {
        $criteria = new Criteria();
        $criteria->addAssociation('orderTransaction');
        $criteria->addAssociation('orderTransaction.order');
        $criteria->addAssociation('orderTransaction.order.currency');
        $criteria->addFilter(new EqualsFilter('orderTransaction.order.id', $orderId));

        if ($isOnlySuccess === true) {
            $criteria->addFilter(new EqualsFilter('status', PaymentCaptureEntity::STATUS_SUCCESS));
        }

        return $this->repository->search($criteria, Context::createDefaultContext());
    }

    /**
     * @return EntityRepository
     */
    public function getRepository(): EntityRepository
    {
        return $this->repository;
    }
}
