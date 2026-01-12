<?php

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Service\Checkout\PaymentsApi;
use Adyen\Shopware\Util\CheckoutStateDataValidator;
use Adyen\Shopware\Util\Currency;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class PaypalPaymentService.
 *
 * @package Adyen\Shopware\Service
 */
readonly class PaypalPaymentService
{
    /**
     * @var PaymentsApi $paymentsApiService
     */
    private PaymentsApi $paymentsApiService;

    /**
     * @param ClientService $clientService
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param ConfigurationService $configurationService
     * @param Currency $currency
     */
    public function __construct(
        private ClientService $clientService,
        private CheckoutStateDataValidator $checkoutStateDataValidator,
        private ConfigurationService $configurationService,
        private Currency $currency,
    ) {
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $context
     *
     * @return void
     * @throws AdyenException
     */
    public function createPayPalPaymentRequest(Cart $cart, SalesChannelContext $context): void
    {
        $this->paymentsApiService = new PaymentsApi(
            $this->clientService->getClient($context->getSalesChannelId())
        );
    }
}
