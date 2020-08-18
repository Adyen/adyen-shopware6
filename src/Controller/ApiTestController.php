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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Controller;

use Adyen\Shopware\Service\ConfigurationService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"administration"})
 *
 * Class ApiTestController
 * @package Adyen\Shopware\Controller
 */
class ApiTestController
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @Route(path="/api/v{version}/_action/adyen/verify")
     *
     * @param RequestDataBag $dataBag
     * @return JsonResponse
     */
    public function check(RequestDataBag $dataBag): JsonResponse
    {
        try {
            $client = new \Adyen\Client();
            $client->setXApiKey($dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.apiKeyTest'));
            $client->setEnvironment(
                $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.environment') ? 'live' : 'test',
                $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.liveEndpointUrlPrefix')
            );
            $service = new \Adyen\Service\Checkout($client);

            $params = array(
                'merchantAccount' => $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.merchantAccount'),
                'countryCode' => 'NL',
                'amount' => array(
                    'currency' => 'EUR',
                    'value' => 1000
                ),
                'channel' => 'Web'
            );
            $result = $service->paymentMethods($params);

            return new JsonResponse(['success' => isset($result['paymentMethods'])]);
        } catch (\Exception $exception) {
            return new JsonResponse(['success' => false]);
        }
    }
}
