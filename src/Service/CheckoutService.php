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

use Adyen\AdyenException;
use Adyen\Service\Checkout;
use Psr\Log\LoggerInterface;

class CheckoutService extends Checkout
{
    /**
     * @var ClientService
     */
    private $client;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ClientService $client,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function startClient($salesChannelId)
    {
        try {
            parent::__construct($this->client->getClient($salesChannelId));
        } catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
