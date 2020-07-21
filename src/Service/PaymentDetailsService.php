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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Service;

use Adyen\Shopware\Service\Repository\SalesChannelRepository;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PaymentDetailsService
{
    /**
     * @var CheckoutService
     */
    private $checkoutService;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var SalesChannelRepository
     */
    private $salesChannelRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * PaymentDetailsService constructor.
     *
     * @param SalesChannelRepository $salesChannelRepository
     * @param LoggerInterface $logger
     * @param CheckoutService $checkoutService
     * @param ConfigurationService $configurationService
     */
    public function __construct(
        SalesChannelRepository $salesChannelRepository,
        LoggerInterface $logger,
        CheckoutService $checkoutService,
        ConfigurationService $configurationService
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->logger = $logger;
        $this->checkoutService = $checkoutService;
        $this->configurationService = $configurationService;
    }

    /**
     * @param SalesChannelContext $context
     * @return array
     */
    public function doPaymentDetails(SalesChannelContext $context): array
    {
        $responseData = ["request" => true];

        return $responseData;
    }
}
