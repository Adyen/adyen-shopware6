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

use Adyen\Shopware\Exception\MissingDataException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ClientService extends \Adyen\Client
{

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var LoggerService
     */
    private $loggerService;

    /**
     * Client constructor.
     * @param SystemConfigService $systemConfigService
     * @param LoggerService $loggerService
     * @throws \Adyen\AdyenException
     * @throws MissingDataException
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerService $loggerService
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->loggerService = $loggerService;

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
            $this->loggerService->error($e->getMessage());
            throw new MissingDataException();
        }

        $this->setXApiKey($apiKey);
        $this->setAdyenPaymentSource("Module", "Version"); //TODO fetch data from the plugin
        $this->setMerchantApplication("Module", "Version"); //TODO fetch data from the plugin
        $this->setExternalPlatform("Platform", "Version"); //TODO fetch data from the platform
        $this->setEnvironment($environment, $liveEndpointUrlPrefix);

        $this->setLogger($this->loggerService);

        //TODO set $this->configuration
    }
}
