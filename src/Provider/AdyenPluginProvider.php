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

namespace Adyen\Shopware\Provider;

use Adyen\Shopware\AdyenPaymentShopware6;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Contracts\Service\ResetInterface;

class AdyenPluginProvider implements ResetInterface
{
    /**
     * @var string|null
     */
    protected ?string $adyenPluginId = null;

    /**
     * @var PluginIdProvider
     */
    protected PluginIdProvider $pluginIdProvider;

    /**
     * @param PluginIdProvider $pluginIdProvider
     */
    public function __construct(PluginIdProvider $pluginIdProvider)
    {
        $this->pluginIdProvider = $pluginIdProvider;
    }

    /**
     * @return string
     */
    public function getAdyenPluginId(): string
    {
        if (isset($this->adyenPluginId)) {
            return $this->adyenPluginId;
        }

        $this->adyenPluginId = $this->pluginIdProvider->getPluginIdByBaseClass(
            AdyenPaymentShopware6::class,
            Context::createDefaultContext()
        );

        return $this->adyenPluginId;
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->adyenPluginId = null;
    }
}
