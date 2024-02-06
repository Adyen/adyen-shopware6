<?php

namespace Adyen\Shopware\Handlers;

class GiftCardPaymentMethodHandler extends \Adyen\Shopware\Handlers\AbstractPaymentMethodHandler
{

    public static function getPaymentMethodCode()
    {
        return 'giftcard';
    }
}
