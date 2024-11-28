<?php

namespace Adyen\Shopware\Util;

use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\PaymentException;

class ShopwarePaymentTokenValidator
{
    /**
     * @var TokenFactoryInterfaceV2
     */
    private TokenFactoryInterfaceV2 $tokenFactory;

    /**
     * @param TokenFactoryInterfaceV2 $tokenFactory
     */
    public function __construct(TokenFactoryInterfaceV2 $tokenFactory)
    {
        $this->tokenFactory = $tokenFactory;
    }

    /**
     * Validates if the Shopware payment token is still valid.
     *
     * @param string|null $paymentToken
     *
     * @return bool
     */
    public function validateToken(?string $paymentToken): bool
    {
        try {
            $token = $this->tokenFactory->parseToken($paymentToken);

            if ($token->isExpired()) {
                return false;
            }

            return true;
        } catch (PaymentException $exception) {
            return false;
        }
    }
}
