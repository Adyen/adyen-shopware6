<?php
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
 * Copyright (c) 2023 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Shopware\Handlers\CardsPaymentMethodHandler;
use Adyen\Shopware\Provider\AdyenPluginProvider;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class PluginPaymentMethodsService
{
    /** @var AdyenPluginProvider */
    protected $adyenPluginProvider;

    /** @var EntityRepositoryInterface */
    protected $paymentMethodRepository;

    public function __construct(
        AdyenPluginProvider $adyenPluginProvider,
        EntityRepositoryInterface $paymentMethodRepository
    ) {
        $this->adyenPluginProvider = $adyenPluginProvider;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    public function getPluginPaymentMethods(string $identifier = null): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('pluginId', $this->adyenPluginProvider->getAdyenPluginId())
        );

        if (isset($identifier)) {
            $criteria->addFilter(
                new EqualsFilter('pluginId', $this->adyenPluginProvider->getAdyenPluginId())
            );
        }

        $paymentMethods = $this->paymentMethodRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities();

        return $paymentMethods->getElements();
    }

    /**
     * @param string $paymentMethod
     * @return string
     * @throws AdyenException
     */
    public function getHandlerIdentifierFromTxVariant(string $paymentMethod): string
    {
        if (CardsPaymentMethodHandler::isSchemePayment($paymentMethod)) {
            return CardsPaymentMethodHandler::class;
        } else {
            $pluginPaymentMethods = $this->getPluginPaymentMethods();

            foreach ($pluginPaymentMethods as $pluginPaymentMethod) {

                if (
                    ($pluginPaymentMethod->getHandlerIdentifier()::getPaymentMethodCode() === 'giftcard' &&
                    $pluginPaymentMethod->getHandlerIdentifier()::getBrand() === $paymentMethod) ||
                    $pluginPaymentMethod->getHandlerIdentifier()::getPaymentMethodCode() === $paymentMethod
                ) {
                    return $pluginPaymentMethod->getHandlerIdentifier();
                }
            }
        }

        throw new AdyenException(sprintf("Payment method %s not found!", $paymentMethod));
    }
}
