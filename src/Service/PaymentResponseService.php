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

use Adyen\Model\Checkout\PaymentDetailsResponse;
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
    private EntityRepository $adyenPaymentResponseRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $orderTransactionRepository;

    /**
     * @param EntityRepository $adyenPaymentResponseRepository
     * @param EntityRepository $orderTransactionRepository
     */
    public function __construct(
        EntityRepository $adyenPaymentResponseRepository,
        EntityRepository $orderTransactionRepository
    ) {
        $this->adyenPaymentResponseRepository = $adyenPaymentResponseRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * @param string $orderId
     * @param Context $context
     *
     * @return PaymentResponseEntity|null
     */
    public function getWithOrderId(string $orderId, Context $context): ?PaymentResponseEntity
    {
        $orderTransaction = $this->orderTransactionRepository
            ->search(
                (new Criteria())
                    ->addFilter((new EqualsFilter('orderId', $orderId)))
                    ->addAssociation('order')
                    ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING)),
                $context
            )
            ->first();
        return $this->getWithOrderTransaction($orderTransaction);
    }

    /**
     * @param string $pspreference
     *
     * @return PaymentResponseEntity|null
     */
    public function getWithPspreference(string $pspreference): ?PaymentResponseEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter((new EqualsFilter('pspreference', $pspreference)));

        return $this->adyenPaymentResponseRepository
            ->search($criteria, Context::createDefaultContext())
            ->first();
    }

    /**
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return PaymentResponseEntity|null
     */
    public function getWithOrderTransaction(OrderTransactionEntity $orderTransaction): ?PaymentResponseEntity
    {
        return $this->adyenPaymentResponseRepository
            ->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('orderTransactionId', $orderTransaction->getId()))
                    ->addAssociation('orderTransaction.order')
                    ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING)),
                Context::createDefaultContext()
            )
            ->first();
    }

    /**
     * @param PaymentResponse|PaymentDetailsResponse $paymentResponse
     * @param OrderTransactionEntity $orderTransaction
     * @param bool $upsert
     *
     * @return void
     */

    public function insertPaymentResponse(
        $paymentResponse,
        OrderTransactionEntity $orderTransaction,
        bool $upsert = true
    ): void {
        if (!($paymentResponse instanceof PaymentResponse) && !($paymentResponse instanceof PaymentDetailsResponse)) {
            throw new \InvalidArgumentException('Invalid $paymentDetailsResponse type.');
        }

        $storedPaymentResponse = $this->getWithOrderTransaction($orderTransaction);
        if ($storedPaymentResponse && $upsert) {
            $fields['id'] = $storedPaymentResponse->getId();
        }

        $fields['orderTransactionId'] = $orderTransaction->getId();

        $fields['resultCode'] = $paymentResponse->getResultCode();
        $fields['response'] = $paymentResponse->__toString();
        $fields['pspreference'] = $paymentResponse->getPspReference() ?? null;

        $this->adyenPaymentResponseRepository->upsert(
            [$fields],
            Context::createDefaultContext()
        );
    }
}
