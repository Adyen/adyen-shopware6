<?php

declare(strict_types=1);
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
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Checkout\Payment\Controller\PaymentController;

class RedirectResultController extends StorefrontController
{
    const CSRF_TOKEN = '_csrf_token';

    /**
     * @var PaymentController
     */
    private $paymentController;

    /**
     * RedirectResultController constructor.
     *
     * @param PaymentController $paymentController
     */
    public function __construct(
        PaymentController $paymentController
    ) {
        $this->paymentController = $paymentController;
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route(
     *     "/adyen/redirect-result",
     *     name="adyen_redirect_result",
     *     defaults={"csrf_protected": false},
     *     methods={"GET", "POST"}
     * )
     *
     * @param Request $request
     */
    public function execute(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        // Get the CSRF token from the query parameters
        $csrfToken = $request->query->get(self::CSRF_TOKEN);

        // Get the sales channel context token from the query parameters
        $salesChannelContextToken = $request->query->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);

        // Remove the parameters above from the query parameters
        $request->query->remove(self::CSRF_TOKEN);
        $request->query->remove(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);

        // Only add the extra parameters above when the method is POST
        if ($request->isMethod('GET')) {
            // Continue with shopware default finalize-tranzaction
            return $this->paymentController->finalizeTransaction($request, $salesChannelContext);
        }

        // Set the CSRF token as a post parameter
        if (!empty($csrfToken)) {
            $request->request->set(self::CSRF_TOKEN, $csrfToken);
        }

        // Set the sales channel context token token as an attribute parameter
        if (!empty($salesChannelContextToken)) {
            $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, $salesChannelContextToken);
        }



        // Continue with shopware default finalize-tranzaction
        return $this->paymentController->finalizeTransaction($request, $salesChannelContext);
    }
}
