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
use Adyen\Shopware\Handlers\ApplePayPaymentMethodHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class PaymentMethodsFilterService
{
    /**
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;

    /**
     * @var AbstractPaymentMethodRoute
     */
    private $paymentMethodRoute;

    public function __construct(
        PaymentMethodsService $paymentMethodsService,
        AbstractPaymentMethodRoute $paymentMethodRoute
    ) {
        $this->paymentMethodsService = $paymentMethodsService;
        $this->paymentMethodRoute = $paymentMethodRoute;
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
                $isSafari = preg_match('/^((?!chrome|android).)*safari/', strtolower($_SERVER['HTTP_USER_AGENT']));

                if ($pmCode == OneClickPaymentMethodHandler::getPaymentMethodCode()) {
                    // For OneClick, remove it if /paymentMethod response has no stored payment methods
                    if (empty($adyenPaymentMethods[OneClickPaymentMethodHandler::getPaymentMethodCode()])) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                } elseif ($pmHandlerIdentifier::$isGiftCard) {
                    // Remove giftcards from checkout list, except the selected giftcard
                    if ($salesChannelContext->getPaymentMethod()->getId() !== $paymentMethodEntity->getId()) {
                        $originalPaymentMethods->remove($paymentMethodEntity->getId());
                    }
                    // Remove ApplePay PM if the browser is not Safari
                } elseif ($pmCode == ApplePayPaymentMethodHandler::getPaymentMethodCode() && $isSafari !== 1) {
                    $originalPaymentMethods->remove($paymentMethodEntity->getId());
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
    ): bool {
        $filteredPaymentMethod = $paymentMethods->filter(
            function (PaymentMethodEntity $paymentMethod) use ($paymentMethodCode, $adyenPluginId) {
                return $paymentMethod->getPluginId() === $adyenPluginId &&
                    $paymentMethod->getHandlerIdentifier()::getPaymentMethodCode() === $paymentMethodCode;
            }
        )->first();

        return isset($filteredPaymentMethod);
    }

    public function getPaymentMethodInCollectionByBrand(
        PaymentMethodCollection $collection,
        string $brand,
        string $adyenPluginId
    ): ?PaymentMethodEntity {
        return $collection->filter(
            function (PaymentMethodEntity $paymentMethod) use ($brand, $adyenPluginId) {
                return $paymentMethod->getPluginId() === $adyenPluginId &&
                    $paymentMethod->getHandlerIdentifier()::getBrand() === $brand;
            }
        )->first();
    }

    public function getAvailableGiftcards(
        SalesChannelContext $context,
        array $adyenPaymentMethods,
        string $adyenPluginId,
        ?PaymentMethodCollection $paymentMethods = null
    ): PaymentMethodCollection
    {
        if (is_null($paymentMethods)) {
            $paymentMethods = $this->getShopwarePaymentMethods($context);
        }

        $giftcards = $this->filterAdyenPaymentMethodsByType($adyenPaymentMethods, 'giftcard');
        $brands = array_column($giftcards, 'brand');

        foreach ($paymentMethods as $entity) {
            $methodHandler = $entity->getHandlerIdentifier();
            /** @var AbstractPaymentMethodHandler $methodHandler */
            if ($entity->getPluginId() !== $adyenPluginId) {
                // Remove non-Adyen payment methods
                $paymentMethods->remove($entity->getId());
            } elseif (!$methodHandler::$isGiftCard || !in_array($methodHandler::getBrand(), $brands)) {
                // Remove non-giftcards and giftcards that are not in /paymentMethods response
                $paymentMethods->remove($entity->getId());
            } else {
                $brand = $methodHandler::getBrand();
                $entity->addExtension('adyenGiftcardData', new ArrayStruct(
                    array_filter($giftcards, function ($method) use ($brand) {
                        return $method['brand'] === $brand;
                    })
                ));
            }
        }

        return $paymentMethods;
    }

    public function filterAdyenPaymentMethodsByType(array $paymentMethodsResponse, string $type): array
    {
        return array_filter($paymentMethodsResponse['paymentMethods'], function ($item) use ($type) {
            return $item['type'] === $type;
        });
    }

    private function getShopwarePaymentMethods(SalesChannelContext $context): PaymentMethodCollection
    {
        $request = new Request();
        $request->query->set('onlyAvailable', '1');

        return $this->paymentMethodRoute->load($request, $context, new Criteria())->getPaymentMethods();
    }
}
