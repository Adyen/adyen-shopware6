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
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2020 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Controller;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Service\Checkout;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"administration"})
 *
 * Class AdminController
 * @package Adyen\Shopware\Controller
 */
class AdminController
{
    /** @var LoggerInterface */
    private $logger;

    /** @var OrderRepository */
    private $orderRepository;

    /** @var RefundService */
    private $refundService;

    /** @var AdyenRefundRepository */
    private $adyenRefundRepository;

    /** @var CurrencyFormatter */
    private $currencyFormatter;

    /**
     * AdminController constructor.
     *
     * @param LoggerInterface $logger
     * @param OrderRepository $orderRepository
     * @param RefundService $refundService
     * @param AdyenRefundRepository $adyenRefundRepository
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        OrderRepository $orderRepository,
        RefundService $refundService,
        AdyenRefundRepository $adyenRefundRepository,
        CurrencyFormatter $currencyFormatter
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->refundService = $refundService;
        $this->adyenRefundRepository = $adyenRefundRepository;
        $this->currencyFormatter = $currencyFormatter;
    }

    /**
     * @Route(path="/api/_action/adyen/verify")
     *
     * @param RequestDataBag $dataBag
     * @return JsonResponse
     */
    public function check(RequestDataBag $dataBag): JsonResponse
    {
        try {
            $client = new Client();
            $client->setXApiKey($dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.apiKeyTest'));
            $client->setEnvironment(
                $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.environment') ? 'live' : 'test',
                $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.liveEndpointUrlPrefix')
            );
            $service = new Checkout($client);

            $params = array(
                'merchantAccount' => $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.merchantAccount'),
            );
            $result = $service->paymentMethods($params);

            $hasPaymentMethods = isset($result['paymentMethods']);
            $response = ['success' => $hasPaymentMethods];
            if (!$hasPaymentMethods) {
                $response['message'] = 'adyen.paymentMethodsMissing';
            }
            return new JsonResponse($response);
        } catch (\Exception $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    /**
     * @Route(
     *     "/api/adyen/refunds",
     *     name="store-api.action.adyen.refund",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function postRefund(Request $request): JsonResponse
    {
        $context = Context::createDefaultContext();
        $orderNumber = $request->request->get('orderNumber');
        if (empty($orderNumber)) {
            return new JsonResponse('Order Number not provided', 400);
        }

        /** @var OrderEntity $order */
        $order = $this->orderRepository->getOrderByOrderNumber(
            $orderNumber,
            $context,
            ['transactions', 'currency']
        );

        if (is_null($order)) {
            return new JsonResponse(sprintf('Unable to find order %s', $orderNumber), 400);
        }

        try {
            $result = $this->refundService->refund($order);
            if (!array_key_exists('pspReference', $result)) {
                $message = sprintf('Invalid response for refund on order %s', $order->getId());
                $this->logger->error($message);
                throw new AdyenException($message);
            }

            $this->refundService->insertAdyenRefund(
                $order,
                $result['pspReference'],
                RefundEntity::SOURCE_SHOPWARE,
                RefundEntity::STATUS_PENDING_NOTI,
            );
        } catch (\Exception $e) {
            return new JsonResponse('An error has occured', 500);
        }

        return new JsonResponse($result);
    }

    /**
     * @Route(
     *     "/api/adyen/orders/{orderNumber}/refunds",
     *     name="api.action.adyen.refund",
     *     methods={"GET"}
     * )
     *
     * @param int $orderNumber
     * @return JsonResponse
     */
    public function getRefunds(int $orderNumber): JsonResponse
    {
        $refunds = $this->adyenRefundRepository->getRefundsByOrderNumber($orderNumber);

        return new JsonResponse($this->buildRefundResponseData($refunds->getElements()));
    }

    /**
     * Build a response containing the data related to the refunds
     *
     * @param array $refunds
     * @return array
     */
    private function buildRefundResponseData(array $refunds)
    {
        $context = Context::createDefaultContext();
        $result = [];
        /** @var RefundEntity $refund */
        foreach ($refunds as $refund) {
            $updatedAt = $refund->getUpdatedAt();
            $order = $refund->getOrder();
            $amount = $this->currencyFormatter->formatCurrencyByLanguage(
                $refund->getAmount() / 100,
                $order->getCurrency()->getIsoCode(),
                $order->getLanguageId(),
                $context
            );
            $result[] = [
                'pspReference' => $refund->getPspReference(),
                'amount' => $amount,
                'source' => $refund->getSource(),
                'status' => $refund->getStatus(),
                'createdAt' => $refund->getCreatedAt()->format('Y-m-d H:i'),
                'updatedAt' => is_null($updatedAt) ? '-' : $updatedAt->format('Y-m-d H:i')
            ];
        }

        return $result;
    }
}
