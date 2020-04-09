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

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Adyen\Shopware\Service\OriginKeyService;

class SalesChannelApiController extends AbstractController
{

    /**
     * @var OriginKeyService
     */
    private $originKey;

    public function __construct(
        OriginKeyService $originKey
    ) {
        $this->originKey = $originKey;
    }

    /**
     * @RouteScope(scopes={"sales-channel-api"})
     * @Route("/sales-channel-api/v1/adyen/origin-key", name="sales-channel-api.action.adyen.origin-key", methods={"GET"})
     */
    public function originKey(): JsonResponse
    {
        return new JsonResponse([$this->originKey->getOriginKeyForOrigin()->getOriginKey()]);
    }
}
