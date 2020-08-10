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

class OriginKeyService
{

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
     * @param CheckoutUtilityService $adyenCheckoutUtilityService
     * @param \Adyen\Shopware\Models\OriginKeyModel $originKeyModel
     */
    public function __construct(
        LoggerInterface $logger,
        CheckoutUtilityService $adyenCheckoutUtilityService,
        OriginKeyModel $originKeyModel
    ) {
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
        $response = [];
        try {
            $response = $this->adyenCheckoutUtilityService->originKeys($params);
        } catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
            return $this->originKeyModel->setOriginKey('');
        }

        if (!empty($response['originKeys'][$host])) {
            $originKey = $response['originKeys'][$host];
        } else {
            $this->logger->error('Empty host response for OriginKey request');
            return $this->originKeyModel->setOriginKey('');
        }

        return $this->originKeyModel->setOriginKey($originKey);
    }
}
