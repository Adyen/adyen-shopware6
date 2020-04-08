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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Shopware\Models\OriginKeyModel;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\Router;

class OriginKey
{

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var OriginKeyModel
     */
    private $originKeyModel;

    /**
     * @var CheckoutUtility
     */
    private $adyenCheckoutUtilityService;

    /**
     * @var Router
     */
    private $router;

    /**
     * OriginKey constructor.
     * @param SystemConfigService $systemConfigService
     * @param CheckoutUtility $adyenCheckoutUtilityService
     * @param \Adyen\Shopware\Models\OriginKeyModel $originKeyModel
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        CheckoutUtility $adyenCheckoutUtilityService,
        OriginKeyModel $originKeyModel,
        Router $router
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->originKeyModel = $originKeyModel;
        $this->adyenCheckoutUtilityService = $adyenCheckoutUtilityService;
        $this->router = $router;
    }

    /**
     * Get origin key for a specific origin using the adyen api library client
     * @return OriginKeyModel
     */
    public function getOriginKeyForOrigin(): OriginKeyModel
    {
        $params = array("originDomains" => array("host")); //TODO get host from Shopware configuration

        try {
            $response = $this->adyenCheckoutUtilityService->originKeys($params);
        } catch (AdyenException $e) {
            //TODO log error
        }

        $originKey = "";

        if (!empty($response['originKeys']["host"])) { //TODO get domain from Shopware configuration
            $originKey = $response['originKeys']["host"];
        } else {
            //TODO log error
        }

        return $this->originKeyModel->setOriginKey($originKey);

    }
}
