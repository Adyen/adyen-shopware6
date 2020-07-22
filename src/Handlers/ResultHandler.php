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

namespace Adyen\Shopware\Handlers;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Shopware\Service\PaymentResponseService;

class ResultHandler
{

    const ADYEN_MERCHANT_REFERENCE = 'adyenMerchantReference';

    private $paymentResponseRepository;
    private $request;
    private $checkoutService;
    private $paymentResponseService;

    public function __construct(
        EntityRepositoryInterface $paymentResponseRepository,
        Request $request,
        CheckoutService $checkoutService,
        PaymentResponseService $paymentResponseService
    ) {
        $this->paymentResponseRepository = $paymentResponseRepository;
        $this->request = $request;
        $this->checkoutService = $checkoutService;
        $this->paymentResponseService = $paymentResponseService;
    }

    public function proccessResult()
    {
        $merchantReference = $this->request->get(self::ADYEN_MERCHANT_REFERENCE);

        if ($merchantReference) {

            //Get the order's payment response
            $paymentResponse = $this->paymentResponseRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('order_number', $merchantReference))
                    ->addAssociation('cart'), //TODO verify if this association works, FK needed?
                Context::createDefaultContext())->first();

            // Validate if cart exists and if we have the necessary objects stored, if not redirect back to order page
            if (empty($paymentResponse) || empty($paymentResponse['paymentData']) || !$paymentResponse->getCart()) {
                return new RedirectResponse('/checkout/cart');
            }
        }

        $response = $this->checkoutService->paymentsDetails(
            array(
                'paymentData' => $paymentResponse['paymentData'],
                'details' => array_merge($this->request->query->all(), $this->request->request->all())
            )
        );

        // Remove stored response since the paymentDetails call is done
        $this->paymentResponseService->delete($paymentResponse->getId());

        //TODO handle response
    }
}
