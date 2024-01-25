<?php

namespace Adyen\Shopware\PaymentMethods;

use Adyen\Shopware\Handlers\GiftCardPaymentMethodHandler;

class GiftCardPaymentMethod implements \Adyen\Shopware\PaymentMethods\PaymentMethodInterface
{

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'GiftCard';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'GiftCard';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return GiftCardPaymentMethodHandler::class;
    }

    /**
     * @inheritDoc
     */
    public function getGatewayCode(): string
    {
        return 'ADYEN_GIFTCARD';
    }

    /**
     * @inheritDoc
     */
    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getLogo(): string
    {
        return 'giftcard.png';
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'redirect';
    }
}
