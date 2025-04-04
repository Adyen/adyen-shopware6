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
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Storefront\Controller;

use Adyen\Shopware\Controller\StoreApi\Notification\NotificationController;
use Adyen\Shopware\Exception\AuthenticationException;
use Adyen\Shopware\Exception\ValidationException;
use Adyen\Webhook\Exception\HMACKeyValidationException;
use Adyen\Webhook\Exception\InvalidDataException;
use Adyen\Webhook\Exception\MerchantAccountCodeException;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class NotificationReceiverController extends StorefrontController
{
    /** @var NotificationController */
    private NotificationController $notificationController;

    /**
     * NotificationReceiverController constructor.
     *
     * @param NotificationController $notificationReceiverService
     */
    public function __construct(NotificationController $notificationReceiverService)
    {
        $this->notificationController = $notificationReceiverService;
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidDataException
     * @throws ValidationException
     * @throws MerchantAccountCodeException
     * @throws HMACKeyValidationException
     * @throws \Adyen\Webhook\Exception\AuthenticationException
     */
    #[Route(
        '/adyen/notification',
        name: 'payment.adyen.notification',
        defaults: ['csrf_protected' => false],
        methods: ['POST']
    )]
    public function execute(Request $request): JsonResponse
    {
        $salesChannelId = $request->attributes->get('sw-sales-channel-id');
        return $this->notificationController->processNotification($salesChannelId, $request);
    }
}
