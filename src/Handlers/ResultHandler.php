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

use Adyen\AdyenException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Adyen\Shopware\Service\CheckoutService;
use Adyen\Shopware\Service\PaymentResponseService;
use Psr\Log\LoggerInterface;

class ResultHandler
{
    /**
     *
     */
    const ADYEN_MERCHANT_REFERENCE = 'adyenMerchantReference';

    /**
     * @var Request
     */
    private $request;

    /**
     * @var CheckoutService
     */
    private $checkoutService;

    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;

    /**
     * ResultHandler constructor.
     * @param Request $request
     * @param CheckoutService $checkoutService
     * @param PaymentResponseService $paymentResponseService
     * @param LoggerInterface $logger
     * @param PaymentResponseHandler $paymentResponseHandler
     */
    public function __construct(
        Request $request,
        CheckoutService $checkoutService,
        PaymentResponseService $paymentResponseService,
        LoggerInterface $logger,
        PaymentResponseHandler $paymentResponseHandler
    ) {
        $this->request = $request;
        $this->checkoutService = $checkoutService;
        $this->paymentResponseService = $paymentResponseService;
        $this->logger = $logger;
        $this->paymentResponseHandler = $paymentResponseHandler;
    }

    /**
     * @return RedirectResponse
     * @throws InconsistentCriteriaIdsException
     */
    public function processResult()
    {
        $merchantReference = $this->request->get(self::ADYEN_MERCHANT_REFERENCE);

        if ($merchantReference) {
            //Get the order's payment response
            $paymentResponse = $this->paymentResponseService
                                    ->getWithMerchantReference($merchantReference);

            // Validate if cart exists and if we have the necessary objects stored, if not redirect back to order page
            if (empty($paymentResponse) || empty($paymentResponse['paymentData']) || !$paymentResponse->getCart()) {
                return new RedirectResponse('/checkout/cart');
            }
        }

        try {
            $response = $this->checkoutService->paymentsDetails(
                array(
                    'paymentData' => $paymentResponse['paymentData'],
                    'details' => array_merge($this->request->query->all(), $this->request->request->all())
                )
            );
        } catch (AdyenException $exception) {
            $this->logger->error($exception->getMessage());
            return new RedirectResponse('/checkout/cart');
        }

        $this->paymentResponseHandler->handlePaymentResponse($response);
    }
}
