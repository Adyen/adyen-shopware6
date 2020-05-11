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

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use Adyen\Shopware\Service\NotificationReceiverService;
use Symfony\Component\HttpFoundation\JsonResponse;

class NotificationReceiverController extends StorefrontController
{
    /** @var NotificationReceiverService */
    private $notificationReceiverService;

    public function __construct(NotificationReceiverService $notificationReceiverService)
    {
        $this->notificationReceiverService = $notificationReceiverService;
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/adyen/notification", name="adyen_notification", defaults={"csrf_protected": false}, methods={"POST"})
     *
     * @param SalesChannelContext $salesChannelContext
     * @param Request $request
     * @return JsonResponse
     */
    public function execute(SalesChannelContext $salesChannelContext, Request $request): JsonResponse
    {
        return $this->notificationReceiverService->process($salesChannelContext, $request);
    }
}
