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
     * Get all captures linked to an order, based on the order id
     *
     * @param string $orderId
     * @return EntityCollection
     */
    public function getOrdersByMerchantReference(string $merchantReference): EntityCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('adyenPayment.order.id', $merchantReference));

        return $this->repository->search($criteria, Context::createDefaultContext());
    }
}
