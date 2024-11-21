<?php declare(strict_types=1);

namespace Adyen\Shopware\PaymentMethods;

use Adyen\Shopware\Handlers\OnlineBankingPolandPaymentMethodHandler;

class OnlineBankingPolandPaymentMethod implements PaymentMethodInterface
{
    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Online Banking Poland';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Online Banking Poland';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return OnlineBankingPolandPaymentMethodHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'ADYEN_ONLINEBANKING_PL';
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getLogo(): string
    {
        return 'onlineBanking_PL.png';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getType(): string
    {
        return 'redirect';
    }
}