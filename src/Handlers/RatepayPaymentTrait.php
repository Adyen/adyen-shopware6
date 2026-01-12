<?php

namespace Adyen\Shopware\Handlers;

use Adyen\Shopware\Models\PaymentRequest;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

trait RatepayPaymentTrait
{
    public static bool $isOpenInvoice = true;
    public static bool $supportsManualCapture = true;
    public static bool $supportsPartialCapture = true;

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param PaymentTransactionStruct $transaction
     * @param OrderEntity $orderEntity
     * @param array $request
     * @param int|null $partialAmount
     * @param array|null $adyenOrderData
     *
     * @return PaymentRequest
     */
    protected function preparePaymentsRequest(
        SalesChannelContext $salesChannelContext,
        PaymentTransactionStruct $transaction,
        OrderEntity $orderEntity,
        array $request = [],
        ?int $partialAmount = null,
        ?array $adyenOrderData = []
    ): PaymentRequest {
        $paymentRequest = parent::preparePaymentsRequest(
            $salesChannelContext,
            $transaction,
            $orderEntity,
            $request,
            $partialAmount,
            $adyenOrderData
        );

        $paymentRequest->setMerchantOrderReference($orderEntity->getOrderNumber());

        return $paymentRequest;
    }
}
