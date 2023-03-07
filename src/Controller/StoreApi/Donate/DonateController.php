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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Controller\StoreApi\Donate;

use Adyen\AdyenException;
use Adyen\Shopware\Handlers\AbstractPaymentMethodHandler;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\DonationService;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;

/**
 * Class DonateController
 * @package Adyen\Shopware\Controller\StoreApi\OrderApi
 * @RouteScope(scopes={"store-api"})
 */
class DonateController
{
    /**
     * @var OrderTransactionRepository
     */
    private $adyenOrderTransactionRepository;
    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;
    /**
     * @var DonationService
     */
    private $donationService;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * StoreApiController constructor.
     *
     * @param DonationService $donationService
     * @param OrderTransactionRepository $adyenOrderTransactionRepository
     * @param EntityRepositoryInterface $orderTransactionRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        DonationService $donationService,
        OrderTransactionRepository $adyenOrderTransactionRepository,
        EntityRepositoryInterface $orderTransactionRepository,
        LoggerInterface $logger
    ) {
        $this->donationService = $donationService;
        $this->adyenOrderTransactionRepository = $adyenOrderTransactionRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->logger = $logger;
    }

    /**
     * @Route(
     *     "/store-api/adyen/donate",
     *     name="store-api.action.adyen.donate",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     */
    public function donate(
        Request $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        $payload = $request->request->get('payload');

        $orderId = $payload['orderId'];
        $currency = $payload['amount']['currency'];
        $value = $payload['amount']['value'];
        $returnUrl = $payload['returnUrl'];

        $transaction = $this->adyenOrderTransactionRepository
            ->getFirstAdyenOrderTransactionByStates($orderId, [OrderTransactionStates::STATE_AUTHORIZED]);

        /** @var AbstractPaymentMethodHandler $paymentMethodIdentifier */
        $paymentMethodIdentifier = $transaction->getPaymentMethod()->getHandlerIdentifier();
        $paymentMethodCode = $paymentMethodIdentifier::getPaymentMethodCode();

        $donationToken = $transaction->getCustomFields()['donationToken'];
        $pspReference = $transaction->getCustomFields()['originalPspReference'];

        // Set donation token as null after first call.
        $storedTransactionCustomFields = $transaction->getCustomFields();
        $storedTransactionCustomFields[PaymentResponseHandler::DONATION_TOKEN] = null;

        $orderTransactionId = $transaction->getId();
        $salesChannelContext->getContext()->scope(
            Context::SYSTEM_SCOPE,
            function (Context $salesChannelContext) use ($orderTransactionId, $storedTransactionCustomFields) {
                $this->orderTransactionRepository->update([
                    [
                        'id' => $orderTransactionId,
                        'customFields' => $storedTransactionCustomFields,
                    ]
                ], $salesChannelContext);
            }
        );

        try {
            $this->donationService->donate(
                $salesChannelContext,
                $donationToken,
                $currency,
                $value,
                $returnUrl,
                $pspReference,
                $paymentMethodCode
            );
        } catch (AdyenException $e) {
            $this->logger->error($e->getMessage());
            return new JsonResponse('An unknown error occurred', $e->getCode());
        }

        return new JsonResponse('Donation completed successfully.');
    }
}
