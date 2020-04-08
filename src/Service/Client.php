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

class Client extends \Adyen\Client
{

    /**
     * Client constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $apiKey = '';

        try {
            //TODO fetch/decrypt API key
        } catch (\Exception $e) {
            //TODO log error
        }

        $this->setXApiKey($apiKey);
        $this->setAdyenPaymentSource("Module", "Version"); //TODO fetch data from the plugin
        $this->setMerchantApplication("Module", "Version"); //TODO fetch data from the plugin
        $this->setExternalPlatform("Platform", "Version"); //TODO fetch data from the platform
        $this->setEnvironment("Env", "prefix"); //TODO fetch data from the plugin

        //TODO use setLogger()
        //TODO set $this->configuration
    }
}
