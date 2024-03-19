<?php

declare(strict_types=1);

namespace Adyen\Shopware\Event;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class PrePaymentDataBuildEvent
{
    /**
     * @var int
     */
    private $amount;

    /**
     * @var AsyncPaymentTransactionStruct
     */
    private $transaction;

    /**
     * @var SalesChannelContext
     */
    private $salesChannelContext;

    public function __construct(
        int $amount,
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ) {
        $this->amount = $amount;
        $this->transaction = $transaction;
        $this->salesChannelContext = $salesChannelContext;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function changeAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function transaction(): AsyncPaymentTransactionStruct
    {
        return $this->transaction;
    }

    public function order(): OrderEntity
    {
        return $this->transaction->getOrder();
    }

    public function salesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }
}
