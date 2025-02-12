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

namespace Adyen\Shopware\Service;

use Adyen\Webhook\Exception\HMACKeyValidationException;
use Adyen\Webhook\Exception\InvalidDataException;
use Adyen\Webhook\Exception\MerchantAccountCodeException;
use Adyen\Webhook\Receiver\NotificationReceiver;
use Adyen\Shopware\Exception\AuthenticationException;
use Adyen\Shopware\Exception\ValidationException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class NotificationReceiverService
{
    /**
     * @var ConfigurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @var NotificationService
     */
    private NotificationService $notificationService;

    /**
     * @var NotificationReceiver
     */
    private NotificationReceiver $notificationReceiver;

    /**
     * @var EntityRepository
     */
    private EntityRepository $salesChannelRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * NotificationReceiverService constructor.
     *
     * @param ConfigurationService $configurationService
     * @param NotificationService $notificationService
     * @param NotificationReceiver $notificationReceiver
     * @param EntityRepository $salesChannelRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        ConfigurationService $configurationService,
        NotificationService $notificationService,
        NotificationReceiver $notificationReceiver,
        EntityRepository $salesChannelRepository,
        LoggerInterface $logger
    ) {
        $this->configurationService = $configurationService;
        $this->notificationService = $notificationService;
        $this->notificationReceiver = $notificationReceiver;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->logger = $logger;
    }

    /**
     * @param Request $requestObject
     * @param string $salesChannelId
     * @return string|JsonResponse
     * @throws AuthenticationException
     * @throws HMACKeyValidationException
     * @throws InvalidDataException
     * @throws MerchantAccountCodeException
     * @throws ValidationException
     * @throws \Adyen\Webhook\Exception\AuthenticationException
     */
    public function process(Request $requestObject, string $salesChannelId): JsonResponse
    {
        $request = $requestObject->request->all();
        $notificationUsername = $this->configurationService->getNotificationUsername($salesChannelId);
        $notificationPassword = $this->configurationService->getNotificationPassword($salesChannelId);

        if (!$notificationUsername || !$notificationPassword) {
            $message = 'Unable to process payment notifications. Notification credentials are not set in config.';
            $this->logger->critical($message);
            return new JsonResponse(['success' => false, 'message' => $message]);
        }

        // Checks if notification is empty
        if (empty($request) || empty($request['notificationItems'][0])) {
            $message = 'Notification is empty';
            $this->logger->critical($message);
            return new JsonResponse(['success' => false, 'message' => $message]);
        }

        // First item in the notification
        $firstNotificationItem = $request['notificationItems'][0]['NotificationRequestItem'];

        // Authorize notification
        if (!$this->notificationReceiver->isAuthenticated(
            $firstNotificationItem,
            $this->configurationService->getMerchantAccount($salesChannelId),
            $notificationUsername,
            $notificationPassword
        )) {
            throw new AuthenticationException();
        }

        $acceptedMessage = '[accepted]';
        $isLive = isset($request['live']) && $request['live'] === 'true';

        // Process each notification item
        foreach ($request['notificationItems'] as $notificationItem) {
            $notificationItem['NotificationRequestItem']['live'] = $isLive;
            if (!$this->processNotificationItem($notificationItem['NotificationRequestItem'], $salesChannelId)) {
                throw new ValidationException();
            }
        }

        // Run the query for checking unprocessed notifications, do this only for test notifications coming from
        // the Adyen Customer Area
        if ($this->notificationReceiver->isTestNotification($firstNotificationItem['pspReference'])) {
            $unprocessedNotifications = $this->notificationService->getNumberOfUnprocessedNotifications();
            if ($unprocessedNotifications > 0) {
                $acceptedMessage .= "\nYou have $unprocessedNotifications unprocessed notifications.";
            }
        }

        $this->logger->info('The result is accepted');

        return new JsonResponse($acceptedMessage);
    }

    /**
     * Returns sales channel by id if it is active
     *
     * @param string $salesChannelId
     *
     * @return SalesChannelEntity|null
     */
    public function getActiveSalesChannelById(string $salesChannelId): ?SalesChannelEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $salesChannelId));
        $criteria->addFilter(new EqualsFilter('active', 1));
        return  $this->salesChannelRepository->search($criteria, Context::createDefaultContext())->first();
    }

    /**
     * Save notification into the database for cron job to execute notification
     *
     * @param $notificationItem
     * @param $salesChannelId
     * @return bool
     * @throws HMACKeyValidationException
     * @throws InvalidDataException
     */
    private function processNotificationItem($notificationItem, $salesChannelId): bool
    {
        $hmacKey = $this->configurationService->getHmacKey($salesChannelId);

        // validate the notification
        if ($this->notificationReceiver->validateHmac($notificationItem, $hmacKey)) {
            // log the notification
            $this->logger->info('The content of the notification item is: ' .
                print_r($notificationItem, true));

            // check if notification already exists
            if (!$this->notificationService->isDuplicateNotification($notificationItem)
                && !empty($notificationItem['merchantReference'])) {
                try {
                    $this->notificationService->insertNotification($notificationItem);
                    return true;
                } catch (\Exception $exception) {
                    $this->logger->error(
                        'Error occurred while saving notification to database',
                        [
                            'pspReference' => $notificationItem['pspReference'],
                            'merchantReference' => $notificationItem['merchantReference']
                        ]
                    );
                    return false;
                }
            } else {
                // duplicated so do nothing but return accepted to Adyen
                $this->logger->info('Duplicated notification received, skipped.');

                return true;
            }
        }

        return false;
    }
}
