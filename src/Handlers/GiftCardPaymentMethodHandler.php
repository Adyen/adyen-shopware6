<?php

namespace Adyen\Shopware\Handlers;

class GiftCardPaymentMethodHandler extends \Adyen\Shopware\Handlers\AbstractPaymentMethodHandler
{
    /**
     * @return string
     */
    public static function getPaymentMethodCode(): string
    {
        return 'giftcard';
    }
}
