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
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Entity\AdyenPayment\AdyenPaymentEntity;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Service\Repository\AdyenPaymentRepository;
use Adyen\Shopware\Util\Currency;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class AdyenPaymentService
{
    const MANUAL_CAPTURE = 'manual_capture';
    const AUTO_CAPTURE = 'auto_capture';

    protected AdyenPaymentRepository $adyenPaymentRepository;
    protected EntityRepository $orderTransactionRepository;

    public function __construct(
        AdyenPaymentRepository $adyenPaymentRepository,
        EntityRepository $orderTransactionRepository
    ) {
        $this->adyenPaymentRepository = $adyenPaymentRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    public function insertAdyenPayment(
        NotificationEntity $notification,
        OrderTransactionEntity $orderTransaction,
        bool $isManualCapture
    ): void {
        $fields = array(
            'pspreference' => $notification->getPspreference(),
            'originalReference' => $notification->getOriginalReference() ?? null,
            'merchantReference' => $notification->getMerchantReference(),
            'merchantOrderReference' => json_decode($notification->getAdditionalData())->merchantOrderReference ?? null,
            'orderTransactionId' => $orderTransaction->getId(),
            'paymentMethod' => $notification->getPaymentMethod(),
            'amountValue' => intval($notification->getAmountValue()),
            'amountCurrency' => $notification->getAmountCurrency(),
            'additionalData' => $notification->getAdditionalData(),
            'captureMode' => $isManualCapture ? self::MANUAL_CAPTURE : self::AUTO_CAPTURE
        );

        $this->adyenPaymentRepository->getRepository()->create(
            [$fields],
            Context::createDefaultContext()
        );
    }

    /**
     * @param string $orderReference
     * @return string|null
     */
    public function getMerchantReferenceFromOrderReference(string $orderReference): ?string
    {
        return $this->adyenPaymentRepository->getMerchantReferenceByMerchantOrderReference($orderReference);
    }

    /**
     * Returns the Adyen payments for the given order ID.
     *
     * @param string $orderId
     * @param string $sort Sorts the response based on created_at column
     * @return array
     */
    public function getAdyenPayments(string $orderId, string $sort = FieldSorting::DESCENDING): array
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

        return $this->adyenPaymentRepository->getRepository()
            ->search(
                (new Criteria())
                    ->addFilter(new EqualsFilter('orderTransactionId', $orderTransaction->getId()))
                    ->addAssociation('orderTransaction.order')
                    ->addSorting(new FieldSorting('createdAt', $sort)),
                Context::createDefaultContext()
            )
            ->getElements();
    }

    /**
     * @param string $pspreference
     * @return AdyenPaymentEntity|null
     */
    public function getAdyenPayment(string $pspreference): ?AdyenPaymentEntity
    {
        return $this->adyenPaymentRepository->getRepository()
            ->search(
                (new Criteria())->addFilter(new EqualsFilter('pspreference', $pspreference)),
                Context::createDefaultContext()
            )
            ->first();
    }

    public function isFullAmountAuthorized(OrderTransactionEntity $orderTransactionEntity): bool
    {
        $amountSum = 0;
        $adyenPaymentOrders = $this->adyenPaymentRepository
            ->getAdyenPaymentsByOrderTransaction($orderTransactionEntity->getId());

        foreach ($adyenPaymentOrders as $adyenPaymentOrder) {
            $amountSum += $adyenPaymentOrder->getAmountValue();
        }

        $currencyUtil = new Currency();
        $totalPrice = $orderTransactionEntity->getAmount()->getTotalPrice();
        $isoCode = $orderTransactionEntity->getOrder()->getCurrency()->getIsoCode();
        $transactionAmount = $currencyUtil->sanitize($totalPrice, $isoCode);

        if ($amountSum >= $transactionAmount) {
            return true;
        }

        return false;
    }

    /**
     * Updates totalRefunded amount og Adyen Payment Entity
     *
     * @param AdyenPaymentEntity $adyenPaymentEntity Entity to be updated
     * @param int $refundAmount Single request refund amount
     *
     * @return void
     */
    public function updateTotalRefundedAmount(AdyenPaymentEntity $adyenPaymentEntity, int $refundAmount): void
    {
        $refundedAmount = $adyenPaymentEntity->getTotalRefunded() + $refundAmount;
        $this->adyenPaymentRepository->getRepository()->update([
            [
                'id' => $adyenPaymentEntity->getId(),
                'totalRefunded' => $refundedAmount
            ]
        ], Context::createDefaultContext());
    }

    public function getPaymentTransactionStruct(
        string $orderTransactionId,
        SalesChannelContext $context
    ): AsyncPaymentTransactionStruct {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->setTitle('payment-service::load-transaction');
        $criteria->addAssociation('order');
        $criteria->addAssociation('paymentMethod.appPaymentMethod.app');

        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context->getContext())->first();

        if ($orderTransaction === null || $orderTransaction->getOrder() === null) {
            throw new InvalidTransactionException($orderTransactionId);
        }

        return new AsyncPaymentTransactionStruct($orderTransaction, $orderTransaction->getOrder(), '');
    }
}
