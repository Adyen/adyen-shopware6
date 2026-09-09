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

namespace Adyen\Shopware\Handlers;

use Adyen\Shopware\Models\PaymentRequest as IntegrationPaymentRequest;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

trait RivertyPaymentTrait
{
    /**
     * Adds the Riverty profile tracking session id as device fingerprint. Profile tracking needs
     * both the shop id and the Experian subdomain, and the id only exists once the storefront has
     * rendered the tracking tag, so headless channels and gift card partials send nothing.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction
     * @param array $stateData
     * @param int|null $partialAmount
     * @param array $orderRequestData
     * @param array $billieData
     *
     * @return IntegrationPaymentRequest
     */
    protected function getAdyenPaymentRequest(
        SalesChannelContext $salesChannelContext,
        AsyncPaymentTransactionStruct $transaction,
        array $stateData,
        ?int $partialAmount,
        array $orderRequestData,
        array $billieData = []
    ): IntegrationPaymentRequest {
        $paymentRequest = parent::getAdyenPaymentRequest(
            $salesChannelContext,
            $transaction,
            $stateData,
            $partialAmount,
            $orderRequestData,
            $billieData
        );

        $paymentMethodType = $stateData['paymentMethod']['type'] ?? static::getPaymentMethodCode();

        if ($paymentMethodType !== static::getPaymentMethodCode()
            || !$this->rivertyFingerprintParamsProvider->isProfileTrackingEnabled(
                $salesChannelContext->getSalesChannelId()
            )
        ) {
            return $paymentRequest;
        }

        $sessionId = $this->rivertyFingerprintParamsProvider->getExistingSessionId();

        if (!is_null($sessionId)) {
            $paymentRequest->setDeviceFingerprint($sessionId);
        }

        return $paymentRequest;
    }

    /**
     * @return void
     */
    protected function clearDeviceFingerprint(): void
    {
        $this->rivertyFingerprintParamsProvider->clear();
    }
}
