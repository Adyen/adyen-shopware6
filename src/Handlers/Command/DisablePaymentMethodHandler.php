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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class DisablePaymentMethodHandler
{
    /**
     * @var AdyenPluginProvider
     */
    private $adyenPluginProvider;

    /**
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelPaymentMethodRepository;

    public function __construct(
        AdyenPluginProvider $adyenPluginProvider,
        EntityRepositoryInterface $paymentMethodRepository,
        EntityRepositoryInterface $salesChannelRepository,
        EntityRepositoryInterface $salesChannelPaymentMethodRepository
    ) {
        $this->adyenPluginProvider = $adyenPluginProvider;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->salesChannelPaymentMethodRepository = $salesChannelPaymentMethodRepository;
    }

    public function run(string $paymentMethodHandlerIdentifier): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('pluginId', $this->adyenPluginProvider->getAdyenPluginId())
        );
        if ($paymentMethodHandlerIdentifier !== 'all') {
            $criteria->addFilter(
                new ContainsFilter('handlerIdentifier', $paymentMethodHandlerIdentifier)
            );
        }

        $paymentMethods = $this->paymentMethodRepository
            ->searchIds($criteria, Context::createDefaultContext())
            ->getData();

        if (!count($paymentMethods)) {
            throw new \Exception('No payment methods found!');
        }

        $criteria = new Criteria();
        $salesChannels = $this->salesChannelRepository
            ->searchIds($criteria, Context::createDefaultContext())
            ->getData();

        foreach ($salesChannels as $salesChannel) {
            foreach ($paymentMethods as $paymentMethod) {
                $this->disablePaymentMethod($paymentMethod['id'], $salesChannel['id']);
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
