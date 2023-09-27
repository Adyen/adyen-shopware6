<?php declare(strict_types=1);
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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\Model\Checkout\PaymentResponse;
use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class PaymentResponseService
{
    /**
     * @var EntityRepository
     */
    private $repository;

    /**
     * @var EntityRepository
     */
    private $orderTransactionRepository;

    public function __construct(
        EntityRepository $repository,
        EntityRepository $orderTransactionRepository
    ) {
        $this->repository = $repository;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    public function getWithOrderNumber(string $orderNumber): ?PaymentResponseEntity
    {
        return $this->repository
            ->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('orderNumber', $orderNumber)),
                Context::createDefaultContext()
            )
            ->first();
    }

    public function getWithOrderId(string $orderId): ?PaymentResponseEntity
    {
        $orderTransaction = $this->orderTransactionRepository
            ->search(
                (new Criteria())
                    ->addFilter((new EqualsFilter('orderId', $orderId)))
                    ->addAssociation('order')
                ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING)),
                Context::createDefaultContext()
            )
            ->first();
        return $this->getWithOrderTransaction($orderTransaction);
    }

    public function getWithOrderTransaction(OrderTransactionEntity $orderTransaction): ?PaymentResponseEntity
    {
        return $this->repository
            ->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('orderTransactionId', $orderTransaction->getId()))
                    ->addAssociation('orderTransaction.order')
                    ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING)),
                Context::createDefaultContext()
            )
            ->first();
    }

    public function insertPaymentResponse(
        PaymentResponse $paymentResponse,
        OrderTransactionEntity $orderTransaction,
        bool $upsert = true
    ): void {
        $storedPaymentResponse = $this->getWithOrderTransaction($orderTransaction);
        if ($storedPaymentResponse && $upsert) {
            $fields['id'] = $storedPaymentResponse->getId();
        }

        $fields['orderTransactionId'] = $orderTransaction->getId();
        $fields['resultCode'] = $paymentResponse->getResultCode();
        $fields['response'] = $paymentResponse->__toString();

        $this->repository->upsert(
            [$fields],
            Context::createDefaultContext()
        );
    }
}
