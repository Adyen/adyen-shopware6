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

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\NotificationReceiverService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class NotificationReceiverController extends StorefrontController
{
    /** @var NotificationReceiverService */
    private NotificationReceiverService $notificationReceiverService;

    /**
     * NotificationReceiverController constructor.
     *
     * @param NotificationReceiverService $notificationReceiverService
     */
    public function __construct(NotificationReceiverService $notificationReceiverService)
    {
        $this->notificationReceiverService = $notificationReceiverService;
    }

    #[Route('/adyen/notification', name: 'payment.adyen.notification', defaults: ['csrf_protected' => false], methods: ['POST'])]
    public function execute(Request $request): JsonResponse
    {
        return $this->notificationReceiverService->process($request);
    }
}
