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
 * Adyen PrestaShop plugin
 *
 * @author Adyen BV <support@adyen.com>
 * @copyright (c) 2020 Adyen B.V.
 * @license https://opensource.org/licenses/MIT MIT license
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 */

namespace Adyen\Shopware\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ClientService extends \Adyen\Client
{

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var LoggerInterface
     */
    private $genericLogger;

    /**
     * Client constructor.
     * @param LoggerInterface $genericLogger
     * @param LoggerInterface $apiLogger
     * @param SystemConfigService $systemConfigService
     * @throws \Adyen\AdyenException
     */
    public function __construct(
        LoggerInterface $genericLogger,
        LoggerInterface $apiLogger,
        SystemConfigService $systemConfigService
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->genericLogger = $genericLogger;

        parent::__construct();

        $apiKey = '';

        try {
            if ($this->systemConfigService->get('AdyenPayment.config.environment')) {
                $environment = 'live';
            } else {
                $environment = 'test';
            }

            $apiKey = $this->systemConfigService->get('AdyenPayment.config.apiKeyTest');

            $liveEndpointUrlPrefix = $this->systemConfigService->get('AdyenPayment.config.liveEndpointUrlPrefix');
        } catch (\Exception $e) {
            $this->genericLogger->error($e->getMessage());
            // TODO: check if $environment is test and, if so, exit with error message
        }

        $this->setXApiKey($apiKey);
        $this->setAdyenPaymentSource("Module", "Version"); //TODO fetch data from the plugin
        $this->setMerchantApplication("Module", "Version"); //TODO fetch data from the plugin
        $this->setExternalPlatform("Platform", "Version"); //TODO fetch data from the platform
        $this->setEnvironment($environment, $liveEndpointUrlPrefix);

        $this->setLogger($apiLogger);

        //TODO set $this->configuration
    }
}
