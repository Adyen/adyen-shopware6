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

use Adyen\Shopware\Models\PaymentRequest;
use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;

trait RatepayPaymentTrait
{
    /**
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction
     * @param array $stateData
     * @param int|null $partialAmount
     * @param array $orderRequestData
     * @param array $billieData
     *
     * @return IntegrationPaymentRequest
     */
    protected function getAdyenPaymentRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction,
        array $stateData,
        ?int $partialAmount,
        array $orderRequestData,
        array $billieData = []
    ): PaymentRequest {
        $paymentRequest = parent::getAdyenPaymentRequest(
            $salesChannelContext,
            $transaction,
            $stateData,
            $partialAmount,
            $orderRequestData,
            $billieData
        );

        $paymentRequest->setMerchantOrderReference($transaction->getOrder()->getOrderNumber());

        return $paymentRequest;
    }
}
