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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Handlers;

use Adyen\Shopware\Exception\PaymentException;
use Adyen\Shopware\Models\PaymentRequest;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

trait RatepayPaymentTrait
{
    /**
     * @param $salesChannelContext
     * @param OrderTransactionEntity $orderTransactionEntity
     * @param $transaction
     * @param OrderEntity $order
     * @param $stateData
     * @param $partialAmount
     * @param $orderRequestData
     * @param array $billieData
     *
     * @return PaymentRequest
     *
     * @throws PaymentException
     */
    protected function getAdyenPaymentRequest(
        $salesChannelContext,
        OrderTransactionEntity $orderTransactionEntity,
        $transaction,
        OrderEntity $order,
        $stateData,
        $partialAmount,
        $orderRequestData,
        array $billieData = []
    ): PaymentRequest {
        $paymentRequest = parent::getAdyenPaymentRequest(
            $salesChannelContext,
            $orderTransactionEntity,
            $transaction,
            $order,
            $stateData,
            $partialAmount,
            $orderRequestData,
            $billieData
        );

        $paymentRequest->setMerchantOrderReference($order->getOrderNumber());

        return $paymentRequest;
    }
}
