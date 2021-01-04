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

use Adyen\Environment;
use Adyen\Service\NotificationReceiver;
use Adyen\Shopware\Exception\AuthenticationException;
use Adyen\Shopware\Exception\HMACKeyValidationException;
use Adyen\Shopware\Exception\ValidationException;
use Adyen\Shopware\Exception\MerchantAccountCodeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Adyen\AdyenException;
use Symfony\Component\HttpFoundation\Request;

class NotificationReceiverService
{
    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var NotificationService
     */
    private $notificationService;

    /**
     * @var NotificationReceiver
     */
    private $notificationReceiver;

    /**
     * @var LoggerInterface
     */
    private $logger;

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
     * @param $notificationItems
     * @return string|JsonResponse
     * @throws AdyenException
     * @throws AuthenticationException
     * @throws HMACKeyValidationException
     * @throws MerchantAccountCodeException
     * @throws ValidationException
     */
    public function process(Request $requestObject)
    {
        $request = $requestObject->request->all();
        $salesChannelId = $requestObject->attributes->get('sw-sales-channel-id');
        $basicAuthUser = $requestObject->server->get('PHP_AUTH_USER');
        $basicAuthPassword = $requestObject->server->get('PHP_AUTH_PW');

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
                $this->configurationService->getMerchantAccount(),
                $basicAuthUser,
                $basicAuthPassword
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
     * @throws AdyenException
     * @throws AuthenticationException
     * @throws HMACKeyValidationException
     * @throws MerchantAccountCodeException
     */
    private function processNotificationItem($notificationItem, $salesChannelId)
    {
        $hmacKey = $this->configurationService->getHmacKey($salesChannelId);

        // validate the notification
        if ($this->notificationReceiver->validateHmac($notificationItem, $hmacKey)) {
            // log the notification
            $this->logger->info('The content of the notification item is: ' .
                print_r($notificationItem, true));

            // skip report notifications
            if ($this->notificationReceiver->isReportNotification($notificationItem['eventCode'])) {
                $this->logger->info('Notification is a REPORT notification from ' .
                    'Adyen Customer Area');
                return true;
            }

            // check if notification already exists
            if (!$this->notificationService->isDuplicateNotification($notificationItem)) {
                $this->notificationService->insertNotification($notificationItem);
                return true;
            } else {
                // duplicated so do nothing but return accepted to Adyen
                $this->logger->info('Duplicated notification received, skipped.');

                return true;
            }
        }

        return false;
    }
}
