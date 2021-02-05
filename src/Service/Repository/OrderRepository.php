<?php

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
     * @return OrderEntity|null
     */
    public function getOrder(string $orderId, Context $context) : ?OrderEntity
    {
        $order = null;

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $orderId));
            $criteria->addAssociation('currency');

            /** @var OrderEntity $order */
            $order = $this->orderRepository->search($criteria, $context)->first();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), [$e]);
        }

        return $order;
    }

    public function getWithOrderNumber(string $orderNumber, Context $context): ?OrderEntity
    {
        return $this->orderRepository->search(
            (new Criteria())
            ->addFilter(new EqualsFilter('orderNumber', $orderNumber))
            ->addAssociation('transactions'),
            $context
        )->first();
    }
}
