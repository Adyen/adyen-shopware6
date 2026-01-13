<?php

namespace Adyen\Shopware\Handlers;

class GiftCardPaymentMethodHandler extends AbstractPaymentMethodHandler
{
    /**
     * @return string
     */
    public static function getPaymentMethodCode(): string
    {
        return 'giftcard';
    }
}
