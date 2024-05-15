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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * NotificationReceiverService constructor.
     *
     * @param ConfigurationService $configurationService
     * @param NotificationService $notificationService
     * @param NotificationReceiver $notificationReceiver
     * @param LoggerInterface $logger
     */
    public function __construct(
        ConfigurationService $configurationService,
        NotificationService $notificationService,
        NotificationReceiver $notificationReceiver,
        LoggerInterface $logger
    ) {
        $this->configurationService = $configurationService;
        $this->notificationService = $notificationService;
        $this->notificationReceiver = $notificationReceiver;
        $this->logger = $logger;
    }

    /**
     * @param Request $requestObject
     * @return JsonResponse
     * @throws AuthenticationException
     * @throws HMACKeyValidationException
     * @throws InvalidDataException
     * @throws MerchantAccountCodeException
     * @throws ValidationException
     * @throws \Adyen\Webhook\Exception\AuthenticationException
     */
    public function process(Request $requestObject): JsonResponse
    {
        $request = $requestObject->request->all();
        $salesChannelId = $requestObject->attributes->get('sw-sales-channel-id');
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

        // validate the notification if HMAC key is configured.
        if (!empty($hmacKey) && !$this->notificationReceiver->validateHmac($notificationItem, $hmacKey)) {
            return false;
        }

        // log the notification
        $this->logger->info('The content of the notification item is: ' .
            print_r($notificationItem, true));

        // check if notification already exists
        if (!$this->notificationService->isDuplicateNotification($notificationItem)) {
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
}
