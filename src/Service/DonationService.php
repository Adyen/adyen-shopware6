<?php
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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class DonationService
{
    const SHOPPER_INTERACTION_CONTAUTH = 'ContAuth';

    /**
     * For donations with iDeal!
     * As iDeal does not support recurring payments and Adyen do not have the IBAN yet
     * when the merchant makes a /payments call, the flow works different from credit card payments.
     * The subsequent call to /donations should include the donationToken and have `sepadirectdebit`
     * specified as payment method to charge the shopper's bank account,
     *
     */
    const PAYMENT_METHOD_CODE_MAPPING = [
        'ideal' => 'sepadirectdebit',
        'storedPaymentMethods' => 'scheme',
        'paywithgoogle' => 'scheme'
    ];

    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @param ClientService $clientService
     * @param ConfigurationService $configurationService
     */
    public function __construct(
        ClientService $clientService,
        ConfigurationService $configurationService
    ) {
        $this->clientService = $clientService;
        $this->configurationService = $configurationService;
    }

    /**
     * @param SalesChannelContext $context
     * @param $donationToken
     * @param $currency
     * @param $value
     * @param $returnUrl
     * @param $pspReference
     * @param $paymentMethodCode
     * @return array|mixed
     * @throws \Adyen\AdyenException
     */
    public function donate(
        SalesChannelContext $context,
        $donationToken,
        $currency,
        $value,
        $returnUrl,
        $pspReference,
        $paymentMethodCode
    ) {
        $responseData = [];

        $requestData = $this->buildDonationRequest(
            $context,
            $donationToken,
            $currency,
            $value,
            $returnUrl,
            $pspReference,
            $paymentMethodCode
        );

        if (!empty($requestData)) {
            $checkoutService = new CheckoutService(
                $this->clientService->getClient($context->getSalesChannel()->getId())
            );
            $responseData = $checkoutService->donations($requestData);
        }

        return $responseData;
    }

    /**
     * @param SalesChannelContext $context
     * @param $donationToken
     * @param $currency
     * @param $value
     * @param $returnUrl
     * @param $pspReference
     * @param $paymentMethodCode
     * @return array
     */
    public function buildDonationRequest(
        SalesChannelContext $context,
        $donationToken,
        $currency,
        $value,
        $returnUrl,
        $pspReference,
        $paymentMethodCode
    ) : array {
        if (isset(self::PAYMENT_METHOD_CODE_MAPPING[$paymentMethodCode])) {
            $paymentMethodCode = self::PAYMENT_METHOD_CODE_MAPPING[$paymentMethodCode];
        }

        return [
            'amount' => [
                'currency' => $currency,
                'value' => $value
            ],
            'reference' => Uuid::randomHex(),
            'donationToken' => $donationToken,
            'donationOriginalPspReference' => $pspReference,
            'donationAccount' => $this->configurationService->getAdyenGivingCharityMerchantAccount(
                $context->getSalesChannel()->getId()
            ),
            'merchantAccount' => $this->configurationService->getMerchantAccount(
                $context->getSalesChannel()->getId()
            ),
            'paymentMethod' => [
                'type' => $paymentMethodCode
            ],
            'shopperInteraction' => self::SHOPPER_INTERACTION_CONTAUTH,
            'returnUrl' => $returnUrl
        ];
    }
}
