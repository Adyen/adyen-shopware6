<?php declare(strict_types=1);
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Handlers\OneClickPaymentMethodHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PaymentMethodsFilterService
{
    /**
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;

    public function __construct(PaymentMethodsService $paymentMethodsService)
    {
        $this->paymentMethodsService = $paymentMethodsService;
    }

    /**
     * Removes Adyen payment methods from the Shopware list if not present in Adyen's /paymentMethods response
     *
     * @param PaymentMethodCollection $originalPaymentMethods
     * @param SalesChannelContext $salesChannelContext
     *
     * @return PaymentMethodCollection
     */
    public function filterShopwarePaymentMethods(
        PaymentMethodCollection $originalPaymentMethods,
        SalesChannelContext $salesChannelContext,
        string $adyenPluginId,
        array $adyenPaymentMethods = []
    ): PaymentMethodCollection {
        if (empty($adyenPaymentMethods)) {
            // Get Adyen /paymentMethods response
            $adyenPaymentMethods = $this->paymentMethodsService->getPaymentMethods($salesChannelContext);
        }

        // If the /paymentMethods response returns empty, remove all Adyen payment methods from the list and return
        if (empty($adyenPaymentMethods['paymentMethods'])) {
            return $originalPaymentMethods->filter(
                function (PaymentMethodEntity $item) use ($adyenPluginId) {
                    return $item->getPluginId() !== $adyenPluginId;
                }
            );
        }

        foreach ($originalPaymentMethods as $paymentMethodEntity) {
            //If this is an Adyen PM installed it will only be enabled if it's present in the /paymentMethods response
            if ($paymentMethodEntity->getPluginId() === $adyenPluginId) {
                /** @var AbstractPaymentMethodHandler $pmHandlerIdentifier */
                $pmHandlerIdentifier = $paymentMethodEntity->getHandlerIdentifier();
                $pmCode = $pmHandlerIdentifier::getPaymentMethodCode();

                if ($pmCode == OneClickPaymentMethodHandler::getPaymentMethodCode()) {
                    // For OneClick, remove it if /paymentMethod response has no stored payment methods
                    if (empty($adyenPaymentMethods[OneClickPaymentMethodHandler::getPaymentMethodCode()])) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                } elseif ($pmHandlerIdentifier::$isGiftCard) {
                    $paymentMethodFoundInResponse = array_filter(
                        $adyenPaymentMethods['paymentMethods'],
                        function ($value) use ($pmHandlerIdentifier) {
                            return isset($value['brand']) && $value['brand'] === $pmHandlerIdentifier::getBrand();
                        }
                    );
                    // Remove the PM if it isn't in the paymentMethods response
                    if (empty($paymentMethodFoundInResponse)) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                } else {
                    // For all other PMs, search in /paymentMethods response for payment method with matching `type`
                    $paymentMethodFoundInResponse = array_filter(
                        $adyenPaymentMethods['paymentMethods'],
                        function ($value) use ($pmCode) {
                            return $value['type'] == $pmCode;
                        }
                    );

                    // Remove the PM if it isn't in the paymentMethods response
                    if (empty($paymentMethodFoundInResponse)) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                }
            }
        }

        return $originalPaymentMethods;
    }

    /**
     * Check if a payment method is available in the PaymentMethodCollection passed
     *
     * @param PaymentMethodCollection $paymentMethods
     * @param string $paymentMethodCode
     * @param string $adyenPluginId
     * @return bool
     */
    public function isPaymentMethodInCollection(
        PaymentMethodCollection $paymentMethods,
        string $paymentMethodCode,
        string $adyenPluginId
    ): bool
    {
        $filteredPaymentMethod = $paymentMethods->filter(
            function (PaymentMethodEntity $paymentMethod) use ($paymentMethodCode, $adyenPluginId) {
                return $paymentMethod->getPluginId() === $adyenPluginId &&
                    $paymentMethod->getHandlerIdentifier()::getPaymentMethodCode() === $paymentMethodCode;
            }
        )->first();

        return isset($filteredPaymentMethod);
    }
}
