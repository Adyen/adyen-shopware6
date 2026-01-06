<?php
declare(strict_types=1);

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

use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Adyen\Shopware\Models\PaymentRequest;

class RatepayDirectdebitPaymentMethodHandler extends AbstractPaymentMethodHandler
{
    public static bool $isOpenInvoice = true;
    public static bool $supportsManualCapture = true;
    public static bool $supportsPartialCapture = true;

    /**
     * @return string
     */
    public static function getPaymentMethodCode(): string
    {
        return 'ratepay_directdebit';
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction
     * @param array $request
     * @param int|null $partialAmount
     * @param array|null $adyenOrderData
     *
     * @return PaymentRequest
     */
    protected function preparePaymentsRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction,
        array $request = [],
        ?int $partialAmount = null,
        ?array $adyenOrderData = []
    ): IntegrationPaymentRequest {
        $paymentRequest = parent::preparePaymentsRequest(
            $salesChannelContext,
            $transaction,
            $request,
            $partialAmount,
            $adyenOrderData
        );

        $paymentRequest->setMerchantOrderReference($transaction->getOrder()->getOrderNumber());

        return $paymentRequest;
    }
}
