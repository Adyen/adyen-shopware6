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
use Adyen\Shopware\Service\PaymentResponseService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class PaymentResponseHandler
{
    // Merchant reference parameter in return GET parameters list
    const ADYEN_MERCHANT_REFERENCE = 'adyenMerchantReference';

    // Merchant reference key in API response
    const MERCHANT_REFERENCE = 'merchantReference';
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;

    public function __construct(
        LoggerInterface $logger,
        PaymentResponseService $paymentResponseService,
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepositoryInterface $orderTransactionRepository
    ) {
        $this->logger = $logger;
        $this->paymentResponseService = $paymentResponseService;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * @param array $response
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     */
    public function handlePaymentResponse(
        array $response,
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ) : RedirectResponse {
        // Retrieve result code from response array
        $resultCode = $response['resultCode'];

        // Retrieve PSP reference from response array if available
        $pspReference = '';
        if (!empty($response['pspReference'])) {
            $pspReference = $response['pspReference'];
        }

        $orderTransactionId = $transaction->getOrderTransaction()->getId();

        // Based on the result code start different payment flows
        switch ($resultCode) {
            case 'Authorised':
                // Tag order as paid
                $context = $salesChannelContext->getContext();
                $this->transactionStateHandler->paid($orderTransactionId, $context);
                // Store psp reference for the payment $pspReference
                // read custom fields before writing to it so we don't mess with other plugins
                $customFields = array_merge(
                    $transaction->getOrderTransaction()->getCustomFields() ?: [],
                    ['originalPspReference' => $pspReference]
                );
                $transaction->getOrderTransaction()->setCustomFields($customFields);
                $this->orderTransactionRepository->update(
                    ['id' => $orderTransactionId, 'customFields' => $customFields],
                    $context
                );
                break;
            case 'Refused':
                // Log Refused
                $this->logger->error(
                    "The payment was refused, order transaction id:  " . $orderTransactionId .
                    " merchant reference: " . $response[self::MERCHANT_REFERENCE]
                );

                // Cancel order
                throw new AsyncPaymentProcessException(
                    $orderTransactionId,
                    'The payment was refused'
                );

                break;
            case 'RedirectShopper':
            case 'IdentifyShopper':
            case 'ChallengeShopper':
                // Store response for cart temporarily until the payment is done
                $this->paymentResponseService->insertPaymentResponse($response);

                //TODO
                return new RedirectResponse('');
                break;
            case 'Received':
            case 'PresentToShopper':
                // Store payments response for later use
                // Return to frontend with additionalData or action
                // Tag the order as waiting for payment
                break;
            case 'Error':
                // Log error
                $this->logger->error(
                    "There was an error with the payment method. id:  " . $orderTransactionId .
                    ' Result code "Error" in response: ' . print_r($response, true)
                );
                // Cancel the order
                throw new AsyncPaymentProcessException(
                    $orderTransactionId,
                    'The payment had an error'
                );
                break;
            default:
                // Unsupported resultCode
                $this->logger->error(
                    "There was an error with the payment method. id:  " . $orderTransactionId .
                    ' Unsupported result code in response: ' . print_r($response, true)
                );
                // Cancel the order
                throw new AsyncPaymentProcessException(
                    $orderTransactionId,
                    'The payment had an error'
                );
                break;
        }
    }
}
