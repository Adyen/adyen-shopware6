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

namespace Adyen\Shopware\Controller;

use Adyen\Shopware\Service\NotificationReceiverService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use GuzzleHttp\Psr7\Request;

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
     * @Route("store-api/v1/adyen/notification", name="adyen_notification", defaults={"csrf_protected": false}, methods={"POST"})
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return Response
     */
    public function execute(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        return $this->notificationReceiverService->process($salesChannelContext, $request->request->all());
    }
}
