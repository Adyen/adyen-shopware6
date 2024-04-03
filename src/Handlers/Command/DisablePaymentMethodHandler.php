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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Handlers\Command;

use Adyen\Shopware\Provider\AdyenPluginProvider;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class DisablePaymentMethodHandler
{
    /**
     * @var AdyenPluginProvider
     */
    private $adyenPluginProvider;

    private $paymentMethodRepository;

    /**
     * @var EntityRepository
     */
    private $salesChannelRepository;

    /**
     * @var EntityRepository
     */
    private $salesChannelPaymentMethodRepository;

    public function __construct(
        AdyenPluginProvider $adyenPluginProvider,
        $paymentMethodRepository,
        EntityRepository $salesChannelRepository,
        EntityRepository $salesChannelPaymentMethodRepository
    ) {
        $this->adyenPluginProvider = $adyenPluginProvider;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->salesChannelPaymentMethodRepository = $salesChannelPaymentMethodRepository;
    }

    public function run(bool $isAll, ?string $paymentMethodHandlerIdentifier): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('pluginId', $this->adyenPluginProvider->getAdyenPluginId())
        );
        if (!$isAll) {
            $criteria->addFilter(
                new ContainsFilter('handlerIdentifier', $paymentMethodHandlerIdentifier)
            );
        }

        $paymentMethods = $this->paymentMethodRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities();

        if (!count($paymentMethods->getElements())) {
            throw new \Exception('No payment methods found!');
        }

        $criteria = new Criteria();
        $salesChannels = $this->salesChannelRepository
            ->searchIds($criteria, Context::createDefaultContext())
            ->getData();

        echo "Following payment methods will be disabled: \n";
        foreach ($paymentMethods->getElements() as $paymentMethod) {
            $paymentMethodName = $paymentMethod->getName();
            echo "* $paymentMethodName \n";

            foreach ($salesChannels as $salesChannel) {
                $this->disablePaymentMethod($paymentMethod->getId(), $salesChannel['id']);
            }
        }
    }

    private function disablePaymentMethod(string $paymentMethodId, string $salesChannelId)
    {
        $this->salesChannelPaymentMethodRepository
            ->delete(
                [['paymentMethodId' => $paymentMethodId, 'salesChannelId' => $salesChannelId]],
                Context::createDefaultContext()
            );
    }
}
