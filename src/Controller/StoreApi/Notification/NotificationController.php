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
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Controller\StoreApi\Notification;

use Adyen\Shopware\Exception\AuthenticationException;
use Adyen\Shopware\Exception\ValidationException;
use Adyen\Shopware\Service\NotificationReceiverService;
use Adyen\Webhook\Exception\HMACKeyValidationException;
use Adyen\Webhook\Exception\InvalidDataException;
use Adyen\Webhook\Exception\MerchantAccountCodeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class NotificationController
{
    /**
     * @var NotificationReceiverService
     */
    private NotificationReceiverService $notificationReceiverService;

    /**
     * NotificationController constructor.
     *
     * @param NotificationReceiverService $notificationReceiverService
     */
    public function __construct(NotificationReceiverService $notificationReceiverService)
    {
        $this->notificationReceiverService = $notificationReceiverService;
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidDataException
     * @throws ValidationException
     * @throws MerchantAccountCodeException
     * @throws \Adyen\Webhook\Exception\AuthenticationException
     * @throws HMACKeyValidationException
     */
    #[Route(
        '/store-api/adyen/notification/{salesChannelId}',
        name: 'store-api.adyen.notification',
        defaults: ['auth_required' => false],
        methods: ['POST']
    )]
    public function processNotification(
        string  $salesChannelId,
        Request $request
    ): JsonResponse {
        if (is_null($this->notificationReceiverService->getActiveSalesChannelById($salesChannelId))) {
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => 'Unable to process payment notifications. Invalid sales channel id is provided'
                ],
                400
            );
        }

        return $this->notificationReceiverService->process($request, $salesChannelId);
    }
}
