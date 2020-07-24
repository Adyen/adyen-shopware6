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

namespace Adyen\Shopware\Service\Builder;

use Adyen\AdyenException;
use Adyen\Shopware\Handlers\ResultHandler;

/**
 * Class ControllerResponseJsonBuilder
 * @package Adyen\Shopware\Service\Builder
 */
class ControllerResponseJsonBuilder
{
    /**
     * @param $action
     * @param array $details
     * @return false|string
     * @throws AdyenException
     */
    public function buildControllerResponseJson($action, $details = array())
    {
        switch ($action) {
            case 'error':
                if (empty($details['message'])) {
                    throw new AdyenException('No message is included in the error response');
                }

                $response = array(
                    'action' => 'error',
                    'message' => $details['message']
                );
                break;
            case 'threeDS2':
                $response = array(
                    'action' => 'threeDS2'
                );

                if (!empty($details['type']) && !empty($details['token'])) {
                    $response['type'] = $details['type'];
                    $response['token'] = $details['token'];
                }
                break;
            case 'redirect':
                if (empty($details['redirectUrl'])) {
                    throw new AdyenException('No redirect url is included in the redirect response');
                }

                $response = array(
                    'action' => 'redirect',
                    'redirectUrl' => $details['redirectUrl']
                );
                break;
            case 'threeDS1':
                if (!empty($details['paRequest']) &&
                    !empty($details['md']) &&
                    !empty($details['issuerUrl']) &&
                    !empty($details[ResultHandler::ADYEN_MERCHANT_REFERENCE]) &&
                    !empty($details['redirectMethod'])) {
                    $response = array(
                        'action' => 'threeDS1',
                        'paRequest' => $details['paRequest'],
                        'md' => $details['md'],
                        'issuerUrl' => $details['issuerUrl'],
                        ResultHandler::ADYEN_MERCHANT_REFERENCE =>
                            $details[ResultHandler::ADYEN_MERCHANT_REFERENCE],
                        'redirectMethod' => $details['redirectMethod']
                    );
                } else {
                    throw new AdyenException("3DS1 details missing");
                }
                break;
            default:
                $response = array(
                    'action' => 'error',
                    'message' => 'Something went wrong'
                );
                break;
        }
        return json_encode($response);
    }
}
