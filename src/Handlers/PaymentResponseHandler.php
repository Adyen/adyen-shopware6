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

use Psr\Log\LoggerInterface;

class PaymentResponseHandler
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
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
            case 'ChallengeShopper':
            case 'RedirectShopper':
                // Store payments response for later use
                // Return to frontend with action
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
