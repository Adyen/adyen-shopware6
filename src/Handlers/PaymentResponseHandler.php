<?php

declare(strict_types=1);
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

namespace Adyen\Shopware\Handlers;

use Adyen\AdyenException;
use Psr\Log\LoggerInterface;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\Builder\ControllerResponseJsonBuilder;

class PaymentResponseHandler
{

    const ADYEN_MERCHANT_REFERENCE = 'adyenMerchantReference';
    const ISSUER = 'issuer';
    const PA_REQUEST = 'paRequest';
    const MD = 'md';
    const ISSUER_URL = 'issuerUrl';
    const REDIRECT_METHOD = 'redirectMethod';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var ControllerResponseJsonBuilder
     */
    private $controllerResponseJsonBuilder;

    public function __construct(
        LoggerInterface $logger,
        PaymentResponseService $paymentResponseService,
        ControllerResponseJsonBuilder $controllerResponseJsonBuilder
    ) {
        $this->logger = $logger;
        $this->paymentResponseService = $paymentResponseService;
        $this->controllerResponseJsonBuilder = $controllerResponseJsonBuilder;
    }

    /**
     * @param array $response
     */
    public function handlePaymentResponse($response)
    {
        // Retrieve result code from response array
        $resultCode = $response['resultCode'];

        // Retrieve PSP reference from response array if available
        $pspReference = '';
        if (!empty($response['pspReference'])) {
            $pspReference = $response['pspReference'];
        }

        // Based on the result code start different payment flows
        switch ($resultCode) {
            case 'Authorised':
                // Tag order as payed

                // Store psp reference for the payment $pspReference

                break;
            case 'Refused':
                // Log Refused
                //TODO replace $id with an actual id
                $id = 'An id with which we can identify the payment';
                $this->logger->error("The payment was refused, id:  " . $id);
                // Cancel order
                break;
            case 'IdentifyShopper':
                // Store response for cart until the payment is done
                $this->paymentResponseService->insertPaymentResponse($response);

                return $this->controllerResponseJsonBuilder->buildControllerResponseJson('threeDS2',
                    array(
                        'type' => 'IdentifyShopper',
                        'token' => $response['authentication']['threeds2.fingerprintToken']
                    )
                );
                break;
            case 'ChallengeShopper':
                // Store response for cart temporarily until the payment is done
                $this->paymentResponseService->insertPaymentResponse($response);

                return $this->controllerResponseJsonBuilder->buildControllerResponseJson('threeDS2',
                    array(
                        'type' => 'ChallengeShopper',
                        'token' => $response['authentication']['threeds2.challengeToken']
                    )
                );
                break;
            case 'RedirectShopper':
                // Check if redirect shopper response data is valid
                if (empty($response['redirect']['url']) ||
                    empty($response['redirect']['method']) ||
                    empty($response['paymentData'])
                ) {
                    throw new AdyenException("There was an error with the payment method, please choose another one.");
                }

                // Store response for cart temporarily until the payment is done
                $this->paymentResponseService->insertPaymentResponse($response);

                $redirectUrl = $response['redirect']['url'];
                $redirectMethod = $response['redirect']['method'];

                // Identify if 3DS1 redirect
                if (!empty($response['redirect']['data']['PaReq']) && !empty($response['redirect']['data']['MD'])) {
                    $paRequest = $response['redirect']['data']['PaReq'];
                    $md = $response['redirect']['data']['MD'];

                    return $this->controllerResponseJsonBuilder->buildControllerResponseJson('threeDS1',
                        array(
                            self::PA_REQUEST => $paRequest,
                            self::MD => $md,
                            self::ISSUER_URL => $redirectUrl,
                            self::REDIRECT_METHOD => $redirectMethod
                        )
                    );
                } else {
                    return $this->controllerResponseJsonBuilder->buildControllerResponseJson('redirect',
                        array(
                            'redirectUrl' => $redirectUrl
                        )
                    );
                }
                break;
                break;
            case 'Received':
            case 'PresentToShopper':
                // Store payments response for later use
                // Return to frontend with additionalData or action
                // Tag the order as waiting for payment
                break;
            case 'Error':
                // Log error
                //TODO replace $id with an actual id
                $id = 'An id with which we can identify the payment';
                $this->logger->error(
                    "There was an error with the payment method. id:  " . $id .
                    ' Result code "Error" in response: ' . print_r($response, true)
                );
                // Cancel the order
                break;
            default:
                // Unsupported resultCode
                //TODO replace $id with an actual id
                $id = 'An id with which we can identify the payment';

                $this->logger->error(
                    "There was an error with the payment method. id:  " . $id .
                    ' Unsupported result code in response: ' . print_r($response, true)
                );
                // Cancel the order
                break;
        }
    }
}
