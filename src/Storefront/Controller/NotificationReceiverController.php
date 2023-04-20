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

use Adyen\AdyenException;
use Adyen\Shopware\Exception\AuthenticationException;
use Adyen\Shopware\Exception\HMACKeyValidationException;
use Adyen\Shopware\Exception\MerchantAccountCodeException;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Adyen\Shopware\Service\NotificationReceiverService;
use Symfony\Component\HttpFoundation\JsonResponse;

class NotificationReceiverController extends StorefrontController
{
    /** @var NotificationReceiverService */
    private $notificationReceiverService;

    /**
     * NotificationReceiverController constructor.
     *
     * @param NotificationReceiverService $notificationReceiverService
     */
    public function __construct(NotificationReceiverService $notificationReceiverService)
    {
        $this->notificationReceiverService = $notificationReceiverService;
    }

    /**
     * @Route(defaults={"_routeScope"={"storefront"}})

     * @Route(
     *     "/adyen/notification",
     *     name="payment.adyen.notification",
     *     defaults={"csrf_protected": false}, methods={"POST"}
     * )
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AdyenException
     * @throws AuthenticationException
     * @throws HMACKeyValidationException
     * @throws MerchantAccountCodeException
     */
    public function execute(Request $request): JsonResponse
    {
        return $this->notificationReceiverService->process($request);
    }
}
