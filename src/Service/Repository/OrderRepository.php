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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class OrderRepository
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $orderRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * OrderRepository constructor.
     *
     * @param EntityRepository $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(EntityRepository $orderRepository, LoggerInterface $logger)
    {
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @param array $associations
     *
     * @return OrderEntity|null
     */
    public function getOrder(string $orderId, Context $context, array $associations = []): ?OrderEntity
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

    /**
     * @param Criteria $criteria
     * @param Context $context
     * @param array $associations
     *
     * @return OrderEntity|null
     */
    public function getOrderByCriteria(Criteria $criteria, Context $context, array $associations = []): ?OrderEntity
    {
        foreach ($associations as $association) {
            $criteria->addAssociation($association);
        }
        return $this->orderRepository->search($criteria, $context)->first();
    }

    /**
     * @param string $orderNumber
     * @param Context $context
     * @param array $associations
     *
     * @return OrderEntity|null
     */
    public function getOrderByOrderNumber(string $orderNumber, Context $context, array $associations = []): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));

        return $this->getOrderByCriteria($criteria, $context, $associations);
    }

    /**
     * @param string $orderId
     * @param array $data
     * @param Context $context
     *
     * @return void
     */
    public function update(string $orderId, array $data, Context $context): void
    {
        $data['id'] = $orderId;
        $this->orderRepository->update([$data], $context);
    }
}
