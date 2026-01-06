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
use Exception;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class PaymentMethodStatusHandler
{
    /**
     * @var AdyenPluginProvider
     */
    private AdyenPluginProvider $adyenPluginProvider;

    private EntityRepository $paymentMethodRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $salesChannelRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $salesChannelPaymentMethodRepository;

    public function __construct(
        AdyenPluginProvider $adyenPluginProvider,
        EntityRepository $paymentMethodRepository,
        EntityRepository $salesChannelRepository,
        EntityRepository $salesChannelPaymentMethodRepository
    ) {
        $this->adyenPluginProvider = $adyenPluginProvider;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->salesChannelPaymentMethodRepository = $salesChannelPaymentMethodRepository;
    }

    /**
     * @param bool $isAll
     * @param bool $isActive
     * @param string|null $paymentMethodHandlerIdentifier
     *
     * @return void
     *
     * @throws Exception
     */
    public function run(bool $isAll, bool $isActive, ?string $paymentMethodHandlerIdentifier): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('pluginId', $this->adyenPluginProvider->getAdyenPluginId())
        );
        $criteria->addFilter(
            new EqualsFilter('active', true)
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
            throw new Exception('No payment methods found!');
        }

        $criteria = new Criteria();
        $salesChannels = $this->salesChannelRepository
            ->searchIds($criteria, Context::createDefaultContext())
            ->getData();

        echo "Following payment methods will be enabled: \n";
        foreach ($paymentMethods->getElements() as $paymentMethod) {
            $paymentMethodName = $paymentMethod->getName();
            echo "* $paymentMethodName \n";

            foreach ($salesChannels as $salesChannel) {
                if ($isActive) {
                    $this->enablePaymentMethod($paymentMethod->getId(), $salesChannel['id']);
                } else {
                    $this->disablePaymentMethod($paymentMethod->getId(), $salesChannel['id']);
                }
            }
        }
    }

    /**
     * @param string $paymentMethodId
     * @param string $salesChannelId
     *
     * @return void
     */
    private function enablePaymentMethod(string $paymentMethodId, string $salesChannelId): void
    {
        $this->salesChannelPaymentMethodRepository->create(
            [
                [
                    'salesChannelId' => $salesChannelId,
                    'paymentMethodId' => $paymentMethodId
                ]
            ],
            Context::createDefaultContext()
        );
    }

    /**
     * @param string $paymentMethodId
     * @param string $salesChannelId
     *
     * @return void
     */
    private function disablePaymentMethod(string $paymentMethodId, string $salesChannelId): void
    {
        $this->salesChannelPaymentMethodRepository
            ->delete(
                [['paymentMethodId' => $paymentMethodId, 'salesChannelId' => $salesChannelId]],
                Context::createDefaultContext()
            );
    }
}
