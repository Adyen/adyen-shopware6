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
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class OrderRepository
{
    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * OrderRepository constructor.
     *
     * @param EntityRepositoryInterface $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @param array $associations
     * @return OrderEntity|null
     */
    public function getOrder(string $orderId, Context $context, array $associations = []) : ?OrderEntity
    {
        $order = null;

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $orderId));

            /** @var OrderEntity $order */
            $order = $this->getOrderByCriteria($criteria, $context, $associations);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), [$e]);
        }

        return $order;
    }

    public function getOrderByCriteria(Criteria $criteria, Context $context, array $associations = []): ?OrderEntity
    {
        foreach ($associations as $association) {
            $criteria->addAssociation($association);
        }
        return $this->orderRepository->search($criteria, $context)->first();
    }

    public function getOrderByOrderNumber(string $orderNumber, Context $context, array $associations = []): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));

        return $this->getOrderByCriteria($criteria, $context, $associations);
    }

    public function update(string $orderId, array $data, Context $context)
    {
        $data['id'] = $orderId;
        $this->orderRepository->update([$data], $context);
    }
}
