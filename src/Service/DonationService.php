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

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class DonationService
{
    const PAYMENT_METHOD_TYPE_SCHEME = 'scheme';
    const SHOPPER_INTERACTION_CONTAUTH = 'ContAuth';

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
     * @param LoggerInterface $logger
     */
    public function __construct(
        ClientService $clientService,
        ConfigurationService $configurationService,
        LoggerInterface $logger
    ) {
        $this->clientService = $clientService;
        $this->configurationService = $configurationService;
        $this->logger = $logger;
    }

    /**
     * @param SalesChannelContext $context
     * @param $donationToken
     * @param $currency
     * @param $value
     * @param $returnUrl
     * @param $pspReference
     * @return array|mixed
     * @throws \Adyen\AdyenException
     */
    public function donate(SalesChannelContext $context, $donationToken, $currency, $value, $returnUrl, $pspReference)
    {
        $responseData = [];

        $requestData = $this->buildDonationRequest(
            $context,
            $donationToken,
            $currency,
            $value,
            $returnUrl,
            $pspReference
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
     * @return array
     */
    public function buildDonationRequest(
        SalesChannelContext $context,
        $donationToken,
        $currency,
        $value,
        $returnUrl,
        $pspReference
    ) : array {
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
                'type' => self::PAYMENT_METHOD_TYPE_SCHEME
            ],
            'shopperInteraction' => self::SHOPPER_INTERACTION_CONTAUTH,
            'returnUrl' => $returnUrl
        ];
    }
}
