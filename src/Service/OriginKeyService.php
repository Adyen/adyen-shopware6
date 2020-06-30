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

class OriginKeyService
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
     * @var CheckoutUtilityService
     */
    private $adyenCheckoutUtilityService;

    /**
     * OriginKey constructor.
     * @param SystemConfigService $systemConfigService
     * @param CheckoutUtilityService $adyenCheckoutUtilityService
     * @param \Adyen\Shopware\Models\OriginKeyModel $originKeyModel
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        CheckoutUtilityService $adyenCheckoutUtilityService,
        OriginKeyModel $originKeyModel
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->originKeyModel = $originKeyModel;
        $this->adyenCheckoutUtilityService = $adyenCheckoutUtilityService;
    }

    /**
     * Get origin key for a specific origin using the adyen api library client
     * @param string $host
     * @return OriginKeyModel
     */
    public function getOriginKeyForOrigin(string $host): OriginKeyModel
    {
        $params = array("originDomains" => array($host));

        try {
            $response = $this->adyenCheckoutUtilityService->originKeys($params);
        } catch (AdyenException $e) {
            die($e->getMessage());
            //TODO log error
        }

        $originKey = "";

        if (!empty($response['originKeys']["host"])) {
            $originKey = $response['originKeys']["host"];
        } else {
            die("Empty host response");
            //TODO log error
        }

        return $this->originKeyModel->setOriginKey($originKey);
    }
}
