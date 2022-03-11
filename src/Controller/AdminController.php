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
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Controller;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Service\Checkout;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\PaymentCapture\PaymentCaptureEntity;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\AdyenPaymentCaptureRepository;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyFormatter;
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
    const ADMIN_DATETIME_FORMAT = 'Y-m-d H:i (e)';

    /** @var LoggerInterface */
    private $logger;

    /** @var OrderRepository */
    private $orderRepository;

    /** @var RefundService */
    private $refundService;

    /** @var AdyenRefundRepository */
    private $adyenRefundRepository;

    /**
     * @var NotificationService
     */
    private NotificationService $notificationService;

    /** @var CurrencyFormatter */
    private $currencyFormatter;

    /** @var Currency */
    private $currencyUtil;

    /** @var CaptureService  */
    private $captureService;

    /** @var AdyenPaymentCaptureRepository */
    private $adyenPaymentCaptureRepository;

    /**
     * AdminController constructor.
     *
     * @param LoggerInterface $logger
     * @param OrderRepository $orderRepository
     * @param RefundService $refundService
     * @param AdyenRefundRepository $adyenRefundRepository
     * @param AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository
     * @param NotificationService $notificationService
     * @param CaptureService $captureService
     * @param CurrencyFormatter $currencyFormatter
     * @param Currency $currencyUtil
     */
    public function __construct(
        LoggerInterface $logger,
        OrderRepository $orderRepository,
        RefundService $refundService,
        AdyenRefundRepository $adyenRefundRepository,
        AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository,
        NotificationService $notificationService,
        CaptureService $captureService,
        CurrencyFormatter $currencyFormatter,
        Currency $currencyUtil
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->refundService = $refundService;
        $this->adyenRefundRepository = $adyenRefundRepository;
        $this->adyenPaymentCaptureRepository = $adyenPaymentCaptureRepository;
        $this->notificationService = $notificationService;
        $this->captureService = $captureService;
        $this->currencyFormatter = $currencyFormatter;
        $this->currencyUtil = $currencyUtil;
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
     * Send a capture request to the Adyen platform
     *
     * @Route(
     *     "/api/adyen/capture",
     *     name="api.adyen_payment_capture.post",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendCaptureRequest(Request $request)
    {
        $context = Context::createDefaultContext();
        $orderId = $request->request->get('orderId');
        // If payload does not contain orderNumber
        if (empty($orderId)) {
            $message = 'Order Id was not provided in request';
            $this->logger->error($message);
            return new JsonResponse($message, 400);
        }

        /** @var OrderEntity $order */
        $order = $this->orderRepository->getOrder($orderId, $context, ['currency']);

        if (is_null($order)) {
            $message = sprintf('Unable to find order %s', $orderId);
            $this->logger->error($message);
            return new JsonResponse($message, 400);
        }

        $currencyIso = $order->getCurrency()->getIsoCode();
        $amountInMinorUnit = $this->currencyUtil->sanitize($order->getAmountTotal(), $currencyIso);

        try {
            $results = $this->captureService
                ->doOpenInvoiceCapture($order->getOrderNumber(), $amountInMinorUnit, $context);
        } catch (CaptureException $e) {
            $this->logger->error($e->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => 'adyen.error',
            ]);
        }

        return new JsonResponse(['success' => true, 'response' => $results]);
    }

    /**
     * Get payment capture requests by order
     *
     * @Route(
     *     "/api/adyen/orders/{orderId}/captures",
     *     name="api.adyen_payment_capture.get",
     *     methods={"GET"}
     * )
     * @param string $orderId
     * @return JsonResponse
     */
    public function getCaptureRequests(string $orderId)
    {
        $captureRequests = $this->adyenPaymentCaptureRepository->getCaptureRequestsByOrderId($orderId);

        return new JsonResponse($this->buildResponseData($captureRequests->getElements()));
    }

    /**
     * Send a refund operation to the Adyen platform
     *
     * @Route(
     *     "/api/adyen/refunds",
     *     name="api.adyen_refund.post",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function postRefund(Request $request): JsonResponse
    {
        $context = Context::createDefaultContext();
        $orderId = $request->request->get('orderId');
        $refundAmount = $request->request->get('refundAmount');
        // If payload does not contain orderNumber
        if (empty($orderId)) {
            $message = 'Order Id was not provided in request';
            $this->logger->error($message);
            return new JsonResponse($message, 400);
        } elseif (empty($refundAmount)) {
            $message = 'Refund amount was not provided in request';
            $this->logger->error($message);
            return new JsonResponse($message, 400);
        }


        /** @var OrderEntity $order */
        $order = $this->orderRepository->getOrder(
            $orderId,
            $context,
            ['transactions', 'currency']
        );

        if (is_null($order)) {
            $message = sprintf('Unable to find order %s', $orderId);
            $this->logger->error($message);
            return new JsonResponse($message, 400);
        } else {
            $currencyIso = $order->getCurrency()->getIsoCode();
            $amountInMinorUnit = $this->currencyUtil->sanitize($refundAmount, $currencyIso);
            if (!$this->refundService->isAmountRefundable($order, $amountInMinorUnit)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'adyen.invalidRefundAmount',
                ]);
            }
        }

        try {
            $result = $this->refundService->refund($order, $amountInMinorUnit);
            // If response does not contain pspReference
            if (!array_key_exists('pspReference', $result)) {
                $message = sprintf('Invalid response for refund on order %s', $order->getOrderNumber());
                throw new AdyenException($message);
            }

            $this->refundService->insertAdyenRefund(
                $order,
                $result['pspReference'],
                RefundEntity::SOURCE_SHOPWARE,
                RefundEntity::STATUS_PENDING_WEBHOOK,
                $amountInMinorUnit
            );
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => 'adyen.error',
            ]);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route(
     *     "/api/adyen/orders/{orderId}/refunds",
     *     name="api.adyen_refund.get",
     *     methods={"GET"}
     * )
     *
     * @param string $orderId
     * @return JsonResponse
     */
    public function getRefunds(string $orderId): JsonResponse
    {
        $refunds = $this->adyenRefundRepository->getRefundsByOrderId($orderId);

        return new JsonResponse($this->buildResponseData($refunds->getElements()));
    }

    /**
     * Get all the notifications for an order.
     *
     * @Route(
     *     "/api/adyen/orders/{orderId}/notifications",
     *      methods={"GET"}
 *     )
     * @param string $orderId
     * @return JsonResponse
     */
    public function getOrderNotifications(string $orderId): JsonResponse
    {
        $order = $this->orderRepository->getOrder($orderId, Context::createDefaultContext());
        $notifications = $this->notificationService->getAllNotificationsByOrderNumber($order->getOrderNumber());

        $response = [];
        /** @var NotificationEntity $notification */
        foreach ($notifications as $notification) {
            $response[] = [
                'pspReference' => $notification->getPspreference(),
                'eventCode' => $notification->getEventCode(),
                'success' => $notification->isSuccess(),
                'amount' => $notification->getAmountValue() . ' ' . $notification->getAmountCurrency(),
                'status' => $notification->isDone()
                    ? NotificationEntity::NOTIFICATION_STATUS_PROCESSED
                    : NotificationEntity::NOTIFICATION_STATUS_PENDING,
                'createdAt' => $notification->getCreatedAt()->format(self::ADMIN_DATETIME_FORMAT),
                'updatedAt' => $notification->getUpdatedAt()->format(self::ADMIN_DATETIME_FORMAT),
            ];
        }

        return new JsonResponse($response);
    }

    /**
     * Build a response containing the data to be displayed
     *
     * @param array $entities
     * @return array
     */
    private function buildResponseData(array $entities)
    {
        $context = Context::createDefaultContext();
        $result = [];
        /** @var Entity $entity */
        foreach ($entities as $entity) {
            $updatedAt = $entity->getUpdatedAt();
            $order = $entity->getOrderTransaction()->getOrder();
            $amount = $this->currencyFormatter->formatCurrencyByLanguage(
                $entity->getAmount() / 100,
                $order->getCurrency()->getIsoCode(),
                $order->getLanguageId(),
                $context
            );
            $result[] = [
                'pspReference' => $entity->getPspReference(),
                'amount' => $amount,
                'rawAmount' => $entity->getAmount(),
                'status' => $entity->getStatus(),
                'createdAt' => $entity->getCreatedAt()->format(self::ADMIN_DATETIME_FORMAT),
                'updatedAt' => is_null($updatedAt) ? '-' : $updatedAt->format(self::ADMIN_DATETIME_FORMAT)
            ];
        }

        return $result;
    }
}
