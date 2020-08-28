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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Checkout\Payment\Controller\PaymentController;

/**
 * also legal
 * @deprecated using redirectToIssuerMethod and redirectFromIssuerMethod in the
 * /payments call is not necessary to process the redirect separately
 */
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

        // Remove it from the query parameters
        $request->query->remove(self::CSRF_TOKEN);

        // Only add the SCRF token when the method is POST
        if ($request->isMethod('GET')) {
            // Continue with shopware default finalize-tranzaction
            return $this->paymentController->finalizeTransaction($request, $salesChannelContext);
        }

        // Set the CSRF token as a post parameter
        if (!empty($csrfToken)) {
            $request->request->set(self::CSRF_TOKEN, $csrfToken);
        }

        // Continue with shopware default finalize-tranzaction
        return $this->paymentController->finalizeTransaction($request, $salesChannelContext);
    }
}
