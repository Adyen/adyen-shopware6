<?php declare(strict_types=1);

namespace Adyen\Shopware\Handlers;

class OnlineBankingFinlandPaymentMethodHandler extends AbstractPaymentMethodHandler
{
    public static function getPaymentMethodCode()
    {
        return 'ebanking_FI';
    }
}
