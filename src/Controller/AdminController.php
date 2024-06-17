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

use Adyen\Client;
use Adyen\Service\Checkout;
use Adyen\Shopware\Entity\AdyenPayment\AdyenPaymentEntity;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Exception\CaptureException;
use Adyen\Shopware\Provider\AdyenPluginProvider;
use Adyen\Shopware\Service\AdyenPaymentService;
use Adyen\Shopware\Service\CaptureService;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\AdyenPaymentCaptureRepository;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Shopware\Service\Repository\OrderTransactionRepository;
use Adyen\Shopware\Util\Currency;
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
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route(defaults: ['_routeScope' => ['administration']])]
class AdminController
{
    const ADMIN_DATETIME_FORMAT = 'Y-m-d H:i (e)';

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var OrderRepository */
    private OrderRepository $orderRepository;

    /** @var RefundService */
    private RefundService $refundService;

    /** @var AdyenRefundRepository */
    private AdyenRefundRepository $adyenRefundRepository;

    /** @var NotificationService */
    private NotificationService $notificationService;

    /** @var CurrencyFormatter */
    private CurrencyFormatter $currencyFormatter;

    /** @var Currency */
    private Currency $currencyUtil;

    /** @var CaptureService  */
    private CaptureService $captureService;

    /** @var AdyenPaymentCaptureRepository */
    private AdyenPaymentCaptureRepository $adyenPaymentCaptureRepository;

    /** @var ConfigurationService */
    private ConfigurationService $configurationService;

    /** @var AdyenPaymentService */
    private AdyenPaymentService $adyenPaymentService;

    /** @var OrderTransactionRepository */
    private OrderTransactionRepository $orderTransactionRepository;

    /** @var AdyenPluginProvider */
    private AdyenPluginProvider $pluginProvider;

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
     * @param AdyenPluginProvider $pluginProvider
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
        OrderTransactionRepository $orderTransactionRepository,
        AdyenPluginProvider $pluginProvider
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
        $this->pluginProvider = $pluginProvider;
    }

    /**
     * @param RequestDataBag $dataBag
     * @return JsonResponse
     */
    #[Route('/api/_action/adyen/verify', name: 'api.action.adyen.verify', methods: ['POST', 'GET'])]
    public function check(RequestDataBag $dataBag): JsonResponse
    {
        try {
            $client = new Client();
            $environment = $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.environment') ?
                'live' :
                'test';
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
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/api/adyen/capture', name: 'api.adyen_payment_capture.post', methods: ['POST'])]
    public function sendCaptureRequest(Request $request): JsonResponse
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
     * @param string $orderId
     * @return JsonResponse
     */
    #[Route('/api/adyen/orders/{orderId}/captures', name: 'api.adyen_payment_capture.get', methods: ['GET'])]
    public function getCaptureRequests(string $orderId): JsonResponse
    {
        $captureRequests = $this->adyenPaymentCaptureRepository->getCaptureRequestsByOrderId($orderId);

        return new JsonResponse($this->buildResponseData($captureRequests->getElements()));
    }

    /**
     * Get payment capture requests by order
     *
     * @param string $orderId
     * @return JsonResponse
     */
    #[Route(
        '/api/adyen/orders/{orderId}/is-capture-allowed',
        name: 'api.adyen_payment_capture_allowed.get',
        methods: ['GET']
    )]
    public function isCaptureAllowed(string $orderId): JsonResponse
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
     * @param string $orderId
     * @return JsonResponse
     */
    #[Route(
        '/api/adyen/orders/{orderId}/is-manual-capture-enabled',
        name: 'api.adyen_payment_capture_enabled.get',
        methods: ['GET']
    )]
    public function isManualCaptureEnabled(string $orderId): JsonResponse
    {
        try {
            $orderTransaction = $this->orderTransactionRepository->getFirstAdyenOrderTransaction($orderId);
            if (is_null($orderTransaction)) {
                return new JsonResponse(false);
            }

            $paymentMethodHandlerIdentifier = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();

            return new JsonResponse(
                $this->captureService->isManualCapture($paymentMethodHandlerIdentifier)
            );
        } catch (Throwable $t) {
            return new JsonResponse(false);
        }
    }

    /**
     * Send a refund operation to the Adyen platform
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/api/adyen/refunds', name: 'api.adyen_refund.post', methods: ['POST'])]
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
            $this->refundService->refund($order, (float) $refundAmount);
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
     * @param string $orderId
     * @return JsonResponse
     */
    #[Route('/api/adyen/orders/{orderId}/refunds', name: 'api.adyen_refund.get', methods: ['GET'])]
    public function getRefunds(string $orderId): JsonResponse
    {
        $refunds = $this->adyenRefundRepository->getRefundsByOrderId($orderId);

        return new JsonResponse($this->buildResponseData($refunds->getElements()));
    }

    /**
     * Get all the notifications for an order.
     *
     * @param string $orderId
     * @return JsonResponse
     */
    #[Route('/api/adyen/orders/{orderId}/notifications', name: 'api.adyen_notifications.get', methods: ['GET'])]
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
     * @param string $orderId
     * @return JsonResponse
     */
    #[Route('/api/adyen/orders/{orderId}/partial-payments', name: 'api.adyen_partial_payments.get', methods: ['GET'])]
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
    private function buildResponseData(array $entities): array
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
     * @param string $notificationId
     * @return JsonResponse
     */
    #[Route(
        '/api/adyen/reschedule-notification/{notificationId}',
        name: 'admin.action.adyen.reschedule-notification',
        methods: ['GET']
    )]
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

    /**
     * @param string $orderId
     * @return JsonResponse
     */
    #[Route(
        '/api/adyen/orders/{orderId}/is-adyen-order',
        name: 'api.adyen_is_adyen_order.get',
        methods: ['GET']
    )]
    public function isAdyenOrder(string $orderId): JsonResponse
    {
        try {
            $transaction = $this->orderTransactionRepository->getFirstAdyenOrderTransaction(
                $orderId,
                Context::createDefaultContext()
            );

            if (!is_null($transaction)) {
                return new JsonResponse(['status' => true]);
            }

            return new JsonResponse(['status' => false]);
        } catch (Throwable $t) {
            $this->logger->error($t->getMessage());
            return new JsonResponse(['message' => "adyen.error"], 500);
        }
    }
}
