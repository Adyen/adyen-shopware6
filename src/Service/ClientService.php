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

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ClientService extends \Adyen\Client
{

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * Client constructor.
     */
    public function __construct(
        SystemConfigService $systemConfigService
    ) {
        $this->systemConfigService = $systemConfigService;

        parent::__construct();

        $apiKey = '';

        try {
            $apiKey = $this->systemConfigService->get('AdyenPayment.config.apiKeyTest');

            if ($this->systemConfigService->get('AdyenPayment.config.environment')) {
                $environment = 'live';
            } else {
                $environment = 'test';
            }

            $liveEndpointUrlPrefix = $this->systemConfigService->get('AdyenPayment.config.liveEndpointUrlPrefix');
        } catch (\Exception $e) {
            die($e->getMessage());
            //TODO log error
        }

        $this->setXApiKey($apiKey);
        $this->setAdyenPaymentSource("Module", "Version"); //TODO fetch data from the plugin
        $this->setMerchantApplication("Module", "Version"); //TODO fetch data from the plugin
        $this->setExternalPlatform("Platform", "Version"); //TODO fetch data from the platform
        $this->setEnvironment($environment, $liveEndpointUrlPrefix);

        //TODO use setLogger()
        //TODO set $this->configuration
    }
}
