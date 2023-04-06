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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Service\Repository;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class AdyenPaymentRepository
{
    /**
     * @var EntityRepository
     */
    private $repository;

    /**
     * AdyenPaymentRepository constructor.
     *
     * @param EntityRepository $repository
     */
    public function __construct(
        EntityRepository $repository
    ) {
        $this->repository = $repository;
    }

    /**
     * @param string $merchantOrderReference
     * @return string|null
     */
    public function getMerchantReferenceByMerchantOrderReference(string $merchantOrderReference): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('merchantOrderReference', $merchantOrderReference));
        $adyenPayment =  $this->repository->search($criteria, Context::createDefaultContext())->first();

        return $adyenPayment ? $adyenPayment->getMerchantReference() : null;
    }

    /**
     * @param string $orderTransactionId
     * @return EntityCollection
     */
    public function getAdyenPaymentsByOrderTransaction(string $orderTransactionId): EntityCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderTransactionId', $orderTransactionId));

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
