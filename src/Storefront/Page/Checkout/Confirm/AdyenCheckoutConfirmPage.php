<?php declare(strict_types=1);

namespace Adyen\Shopware\Storefront\Page\Checkout\Confirm;

use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;

class AdyenCheckoutConfirmPage extends CheckoutConfirmPage
{
    /**
     * @var array
     */
    protected $adyenData;

    /**
     * @return array
     */
    public function getAdyenData(): array
    {
        return $this->adyenData;
    }

    /**
     * @param array $adyenData
     */
    public function setAdyenData(array $adyenData): void
    {
        $this->adyenData = $adyenData;
    }

}
