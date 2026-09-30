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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Service;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Model\Checkout\PaymentReversalRequest;
use Adyen\Service\Checkout\ModificationsApi;
use Adyen\Shopware\Util\Idempotency;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reverses (cancels or refunds, depending on the capture state) a payment for which no Shopware order exists.
 *
 * @package Adyen\Shopware\Service
 */
class PaymentReversalService
{
    /**
     * @param ClientService $clientService
     * @param ConfigurationService $configurationService
     * @param Idempotency $idempotencyHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ClientService $clientService,
        private readonly ConfigurationService $configurationService,
        private readonly Idempotency $idempotencyHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sends POST /payments/{pspReference}/reversals. Never throws, so the caller can always continue with
     * its own error handling.
     *
     * The idempotency key depends only on the merchant account, the merchant reference and the PSP reference,
     * so the in-process reversal and the webhook fallback for the same payment are one operation at Adyen.
     *
     * @param string $salesChannelId
     * @param string $pspReference PSP reference of the payment to reverse
     * @param string $merchantReference Order number sent to Adyen with the payment
     * @param array $logContext
     *
     * @return string|null PSP reference of the reversal, null when it could not be performed
     */
    public function reverse(
        string $salesChannelId,
        string $pspReference,
        string $merchantReference,
        array $logContext = []
    ): ?string {
        $logContext += [
            'pspReference' => $pspReference,
            'merchantReference' => $merchantReference,
            'salesChannelId' => $salesChannelId,
        ];

        try {
            $merchantAccount = $this->configurationService->getMerchantAccount($salesChannelId);
            if (empty($merchantAccount)) {
                throw new AdyenException('No Merchant Account set for the sales channel.');
            }

            $request = new PaymentReversalRequest();
            $request->setMerchantAccount($merchantAccount);
            $request->setReference($merchantReference);

            $idempotencyKey = $this->idempotencyHelper->createKeyFromRequest(
                $request->toArray(),
                ['pspReference' => $pspReference]
            );

            $this->clientService->logRequest(
                $request->toArray(),
                Client::API_CHECKOUT_VERSION,
                sprintf('/payments/%s/reversals', $pspReference),
                $salesChannelId
            );

            $modificationsApi = new ModificationsApi($this->clientService->getClient($salesChannelId));
            $response = $modificationsApi->refundOrCancelPayment(
                $pspReference,
                $request,
                ['idempotencyKey' => $idempotencyKey]
            );

            $this->clientService->logResponse($response->toArray(), $salesChannelId);

            if (empty($response->getPspReference())) {
                throw new AdyenException('Invalid response for the payment reversal request.');
            }

            $this->logger->warning('Reversed a payment without a Shopware order.', $logContext + [
                'reversalPspReference' => $response->getPspReference(),
                'status' => $response->getStatus(),
            ]);

            return $response->getPspReference();
        } catch (Throwable $exception) {
            $this->logger->critical(
                'Could not reverse a payment without a Shopware order. Manual action required.',
                $logContext + ['errorMessage' => $exception->getMessage()]
            );

            return null;
        }
    }
}
