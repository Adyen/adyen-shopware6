<?php declare(strict_types=1);

namespace Adyen\Shopware\Handlers;

class OnlineBankingPolandPaymentMethodHandler extends AbstractPaymentMethodHandler
{
    public static function getPaymentMethodCode()
    {
        return 'onlineBanking_PL';
    }
}
