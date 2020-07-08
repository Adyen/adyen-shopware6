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
use Psr\Log\LoggerInterface;
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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * OriginKey constructor.
     * @param LoggerInterface $logger
     * @param SystemConfigService $systemConfigService
     * @param CheckoutUtilityService $adyenCheckoutUtilityService
     * @param \Adyen\Shopware\Models\OriginKeyModel $originKeyModel
     */
    public function __construct(
        LoggerInterface $logger,
        SystemConfigService $systemConfigService,
        CheckoutUtilityService $adyenCheckoutUtilityService,
        OriginKeyModel $originKeyModel
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->adyenCheckoutUtilityService = $adyenCheckoutUtilityService;
        $this->originKeyModel = $originKeyModel;
        $this->logger = $logger;
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
            $this->logger->error($e->getMessage());
            exit;
        }

        $originKey = "";

        if (!empty($response['originKeys']["host"])) {
            $originKey = $response['originKeys']["host"];
        } else {
            $this->logger->error('Empty host response for OriginKey request');
            exit;
        }

        return $this->originKeyModel->setOriginKey($originKey);
    }
}
