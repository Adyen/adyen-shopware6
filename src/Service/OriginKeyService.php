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
     * @var LoggerService
     */
    private $loggerService;

    /**
     * OriginKey constructor.
     * @param SystemConfigService $systemConfigService
     * @param CheckoutUtilityService $adyenCheckoutUtilityService
     * @param \Adyen\Shopware\Models\OriginKeyModel $originKeyModel
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        CheckoutUtilityService $adyenCheckoutUtilityService,
        OriginKeyModel $originKeyModel,
        LoggerService $loggerService
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->adyenCheckoutUtilityService = $adyenCheckoutUtilityService;
        $this->originKeyModel = $originKeyModel;
        $this->loggerService = $loggerService;
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
            $this->loggerService->error($e->getMessage());
            exit;
        }

        $originKey = "";

        if (!empty($response['originKeys']["host"])) {
            $originKey = $response['originKeys']["host"];
        } else {
            $this->loggerService->error('Empty host response for OriginKey request');
            exit;
        }

        return $this->originKeyModel->setOriginKey($originKey);

    }
}
