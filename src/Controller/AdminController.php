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
use Adyen\Shopware\Entity\AdyenPayment\AdyenPaymentEntity;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\AdyenPaymentCaptureRepository;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use src\Util\Currency;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(defaults={"_routeScope"={"administration"}})
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

    /** @var NotificationService */
    private $notificationService;

    /** @var CurrencyFormatter */
    private $currencyFormatter;

    /** @var Currency */
    private $currencyUtil;

    /** @var CaptureService  */
    private $captureService;

    /** @var AdyenPaymentCaptureRepository */
    private $adyenPaymentCaptureRepository;

    /** @var ConfigurationService */
    private $configurationService;

    /** @var AdyenPaymentService */
    private $adyenPaymentService;

    /** @var OrderTransactionRepository */
    private $orderTransactionRepository;

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
     * @param ConfigurationService $configurationService
     * @param AdyenPaymentService $adyenPaymentService
     * @param OrderTransactionRepository $orderTransactionRepository
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
        Currency $currencyUtil,
        ConfigurationService $configurationService,
        AdyenPaymentService $adyenPaymentService,
        OrderTransactionRepository $orderTransactionRepository
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
        $this->configurationService = $configurationService;
        $this->adyenPaymentService = $adyenPaymentService;
        $this->orderTransactionRepository = $orderTransactionRepository;
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
            $environment = $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.environment') ? 'live' : 'test';
            $client->setXApiKey(
                $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.apiKey' . ucfirst($environment))
            );
            $client->setEnvironment(
                $environment,
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
        } catch (Exception $exception) {
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
        $adyenPayments = $this->adyenPaymentService->getAdyenPayments($orderId);

        if (isset($adyenPayments)) {
            // This line assumes there can be only one manual capture partial payment in an order.
            $amountInMinorUnit = $this->captureService->getRequiredCaptureAmount($orderId);
        } else {
            $amountInMinorUnit = $this->currencyUtil->sanitize($order->getAmountTotal(), $currencyIso);
        }

        try {
            $results = $this->captureService
                ->doOpenInvoiceCapture($order->getOrderNumber(), $amountInMinorUnit, $context);
        } catch (CaptureException $e) {
            $this->logger->error($e->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
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
     * Get payment capture requests by order
     *
     * @Route(
     *     "/api/adyen/orders/{orderId}/is-capture-allowed",
     *     name="api.adyen_payment_capture_allowed.get",
     *     methods={"GET"}
     * )
     * @param string $orderId
     * @return JsonResponse
     */
    public function isCaptureAllowed(string $orderId)
    {
        $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransactionByStates(
            $orderId,
            [OrderTransactionStates::STATE_AUTHORIZED, OrderTransactionStates::STATE_IN_PROGRESS]
        );

        if (isset($orderTransaction)) {
            $isFullAmountAuthorised = $this->adyenPaymentService->isFullAmountAuthorized($orderTransaction);
            $isRequiredAmountCaptured = $this->captureService->isRequiredAmountCaptured($orderTransaction);
            $isPaymentMethodSupportsManualCapture = $this->captureService->isManualCapture(
                $orderTransaction->getPaymentMethod()->getHandlerIdentifier()
            );

            if ($isPaymentMethodSupportsManualCapture && $isFullAmountAuthorised && !$isRequiredAmountCaptured) {
                $isCaptureAllowed = true;
            } else {
                $isCaptureAllowed = false;
            }
        } else {
            $isCaptureAllowed = false;
        }

        return new JsonResponse($isCaptureAllowed);
    }

    /**
     * @Route(
     *     "/api/adyen/orders/{orderId}/is-manual-capture-enabled",
     *     name="api.adyen_payment_capture_enabled.get",
     *     methods={"GET"}
     * )
     * @param string $orderId
     * @return JsonResponse
     */
    public function isManualCaptureEnabled(string $orderId)
    {
        try {
            $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransaction($orderId);
            $paymentMethodHandlerIdentifier = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();

            return new JsonResponse(
                $this->captureService->isManualCapture($paymentMethodHandlerIdentifier)
            );
        } catch (Exception $e) {
            return new JsonResponse(false);
        }
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

            $statesToSearch = RefundService::REFUNDABLE_STATES;
            $orderTransaction = $this->refundService->getAdyenOrderTransactionForRefund($order, $statesToSearch);
            $adyenRefund = $this->adyenRefundRepository
                ->getRefundForOrderByPspReference($orderTransaction->getId(), $result['pspReference']);

            if (is_null($adyenRefund)) {
                $this->refundService->insertAdyenRefund(
                    $order,
                    $result['pspReference'],
                    RefundEntity::SOURCE_SHOPWARE,
                    RefundEntity::STATUS_PENDING_WEBHOOK,
                    $amountInMinorUnit
                );
            }
        } catch (Exception $e) {
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
                'updatedAt' => $notification->getUpdatedAt()
                    ? $notification->getUpdatedAt()->format(self::ADMIN_DATETIME_FORMAT)
                    : '',
                'notificationId' => $notification->getId(),
                'canBeRescheduled' => $this->notificationService->canBeRescheduled($notification),
                'errorCount' => $notification->getErrorCount(),
                'errorMessage' => $notification->getErrorMessage()
            ];
        }

        return new JsonResponse($response);
    }

    /**
     * Get all the authorised payments of an order from adyen_payment table.
     *
     * @Route(
     *     "/api/adyen/orders/{orderId}/partial-payments",
     *      methods={"GET"}
     *     )
     * @param string $orderId
     * @return JsonResponse
     */
    public function getPartialPayments(string $orderId): JsonResponse
    {
        $order = $this->orderRepository->getOrder($orderId, Context::createDefaultContext());
        $adyenPayments = $this->adyenPaymentService->getAdyenPayments($orderId);
        $response = [];

        /** @var AdyenPaymentEntity $adyenPayments */
        foreach ($adyenPayments as $payment) {
            $response[] = [
                'pspReference' => $payment->pspreference,
                'method' => $payment->paymentMethod,
                'amount' => $payment->amountValue . ' ' . $payment->amountCurrency,
                'caLink' => sprintf(
                    "https://ca-%s.adyen.com/ca/ca/accounts/showTx.shtml?pspReference=%s&txType=Payment",
                    $this->configurationService->getEnvironment($order->getSalesChannelId()),
                    $payment->pspreference
                )
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

    /**
     * @Route(
     *     "/api/adyen/reschedule-notification/{notificationId}",
     *     name="admin.action.adyen.reschedule-notification",
     *     methods={"GET"}
     * )
     *
     * @param string $notificationId
     * @return JsonResponse
     */
    public function rescheduleNotification(string $notificationId): JsonResponse
    {
        $notification = $this->notificationService->getNotificationById($notificationId);

        if ($this->notificationService->canBeRescheduled($notification)) {
            $scheduledProcessingTime = $this->notificationService->calculateScheduledProcessingTime(
                $notification,
                true
            );
            // If notification was stuck in state Processing=true, reset the state and reschedule.
            if ($notification->getProcessing()) {
                $this->notificationService->changeNotificationState(
                    $notification->getId(),
                    'processing',
                    false
                );
            }
            $this->notificationService->setNotificationSchedule($notification->getId(), $scheduledProcessingTime);
        }

        return new JsonResponse($notificationId);
    }
}
