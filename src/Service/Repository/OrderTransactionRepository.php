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
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepositoryInterface $repository,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * @param string $orderId
     * @param bool $includeRefundState
     * @return OrderTransactionEntity|null
     */
    public function getFirstAdyenRefundableOrderTransactionByOrderId(
        string $orderId,
        bool $includeRefundState = false
    ): ?OrderTransactionEntity {

        // Because of scenarios where the refund has already been processed but then a REFUND_FAILED noti is received
        // we may occasionally need to search for this status as well.
        $states = RefundService::REFUNDABLE_STATES;
        if ($includeRefundState) {
            $states[] = OrderTransactionStates::STATE_REFUNDED;
        }

        $criteria = new Criteria();
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order');
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('paymentMethod.plugin');
        $criteria->addFilter(new EqualsFilter('order.id', $orderId));
        $criteria->addFilter(
            new EqualsAnyFilter('stateMachineState.technicalName', $states)
        );
        $criteria->addFilter(
            new EqualsFilter('paymentMethod.plugin.name', ConfigurationService::BUNDLE_NAME)
        );

        return $this->repository->search($criteria, Context::createDefaultContext())->first();
    }
}
