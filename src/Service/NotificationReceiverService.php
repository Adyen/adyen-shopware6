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

use Adyen\Shopware\Exception\AuthenticationException;
use Adyen\Shopware\Exception\AuthorizationException;
use Adyen\Shopware\Exception\HMACKeyValidationException;
use Adyen\Shopware\Exception\ValidationException;
use Adyen\Shopware\Exception\MerchantAccountCodeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Adyen\AdyenException;
use Adyen\Util\HmacSignature;
use Symfony\Component\HttpFoundation\Request;

class NotificationReceiverService
{
    /**
     * @var HmacSignature
     */
    private $hmacSignature;

    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var NotificationService
     */
    private $notificationService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * NotificationReceiverService constructor.
     *
     * @param ConfigurationService $configurationService
     * @param HmacSignature $hmacSignature
     * @param LoggerInterface $logger
     * @param NotificationService $notificationService
     */
    public function __construct(
        ConfigurationService $configurationService,
        HmacSignature $hmacSignature,
        LoggerInterface $logger,
        NotificationService $notificationService
    ) {
        $this->configurationService = $configurationService;
        $this->hmacSignature = $hmacSignature;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    /**
     * @param $notificationItems
     * @return string|JsonResponse
     * @throws AdyenException
     * @throws AuthenticationException
     * @throws AuthorizationException
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

        // Validate if notification is not empty
        if (empty($request)) {
            $message = 'Notification is empty';
            $this->logger->critical($message);
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => $message
                ]
            );
        }

        // Checks if notification is a test notification
        $isTestNotification = $this->isTestNotification($request);

        // Authorize notification
        if (!$this->isAuthorized($isTestNotification, $basicAuthUser, $basicAuthPassword)) {
            throw new AuthorizationException();
        }

        // Is the plugin configured to live environment
        $pluginMode = $this->configurationService->getEnvironment();

        // Validate notification and process the notification items
        if (!empty($request['live']) && $this->validateNotificationMode($request['live'], $pluginMode)) {
            $acceptedMessage = '[accepted]';

            // Process each notification item
            foreach ($request['notificationItems'] as $notificationItem) {
                $notificationItem['NotificationRequestItem']['live'] = $request['live'];
                if (!$this->processNotificationItem($notificationItem['NotificationRequestItem'], $salesChannelId)) {
                    throw new ValidationException();
                }
            }

            // Run the query for checking unprocessed notifications, do this only for test notifications coming from
            // the Adyen Customer Area
            if ($isTestNotification) {
                $unprocessedNotifications = $this->notificationService->getNumberOfUnprocessedNotifications();
                if ($unprocessedNotifications > 0) {
                    $acceptedMessage .= "\nYou have $unprocessedNotifications unprocessed notifications.";
                }
            }
            $this->logger->info('The result is accepted');

            return new JsonResponse(
                $this->returnAccepted($acceptedMessage)
            );
        } else {
            $message = 'Mismatch between Live/Test modes of Shopware store and the Adyen platform';
            $this->logger->critical($message);
            return new JsonResponse(
                array(
                    'success' => false,
                    'message' => $message
                )
            );
        }
    }

    /**
     * Validation of the notification
     * Testing the notification against the plugin environment and the HMAC signature
     *
     * @param $notification
     * @param $merchantAccount
     * @param $hmacKey
     * @return bool
     * @throws AdyenException
     * @throws HMACKeyValidationException
     * @throws MerchantAccountCodeException
     */
    protected function isValidated($notification, $merchantAccount, $hmacKey)
    {
        // Check if the notification is a test notification
        $isTestNotification = $this->isTestNotificationPspReference($notification['pspReference']);

        // Validate if notification or configuration merchant account value is missing
        if (empty($notification['merchantAccountCode']) || empty($merchantAccount)) {
            if ($isTestNotification) {
                $message = 'MerchantAccountCode or merchant account configuration is empty.';
                throw new MerchantAccountCodeException($message);
            }

            return false;
        }

        // Validate HMAC signature
        if (!$this->hmacSignature->isValidNotificationHMAC($hmacKey, $notification)) {
            if ($isTestNotification) {
                $message = 'HMAC key validation failed';
                throw new HMACKeyValidationException($message);
            }

            return false;
        }

        // Notification is validated
        return true;
    }

    /**
     * Authorize notification based on Basic Authentication
     *
     * @param $isTestNotification
     * @param $requestUser
     * @param $requestPassword
     * @return bool
     * @throws AuthenticationException
     */
    private function isAuthorized($isTestNotification, $requestUser, $requestPassword)
    {
        // Retrieve username and password from config
        $userName = $this->configurationService->getNotificationUsername();
        $password = $this->configurationService->getNotificationPassword();

        // Validate if username and password is sent
        if ((is_null($requestUser) || is_null($requestPassword))) {
            if ($isTestNotification) {
                $message = 'PHP_AUTH_USER or PHP_AUTH_PW is not in the request.';
                throw new AuthenticationException($message);
            }

            return false;
        }

        // Validate if username and password is configured
        if ((is_null($userName) || is_null($password))) {
            if ($isTestNotification) {
                $message = 'Notifications username and/or password are not configured.';
                throw new AuthenticationException($message);
            }

            return false;
        }

        // Validate the username and password
        $usernameCmp = hash_equals($userName, $requestUser);
        $passwordCmp = hash_equals($password, $requestPassword);
        if ($usernameCmp === false || $passwordCmp === false) {
            if ($isTestNotification) {
                $message = 'Username (PHP_AUTH_USER) and\or password (PHP_AUTH_PW) are not the same as ' .
                    ' configuration settings';
                throw new AuthenticationException($message);
            }

            return false;
        }

        // The notification is authorized
        return true;
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
    protected function processNotificationItem($notificationItem, $salesChannelId)
    {
        $merchantAccount = $this->configurationService->getMerchantAccount();
        $hmacKey = $this->configurationService->getHmacKey($salesChannelId);

        // validate the notification
        if ($this->isValidated($notificationItem, $merchantAccount, $hmacKey)) {
            // log the notification
            $this->logger->info('The content of the notification item is: ' .
                print_r($notificationItem, true));

            // skip report notifications
            if ($this->isReportNotification($notificationItem['eventCode'])) {
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

    /**
     * Checks if notification mode and the store mode configuration matches
     *
     * @param string|bool $notificationMode
     * @param bool $pluginMode
     * @return bool
     */
    protected function validateNotificationMode($notificationMode, $pluginMode)
    {
        // Notification mode can be a string or a boolean
        if (($pluginMode && ($notificationMode == 'false' || $notificationMode == false)) ||
            (!$pluginMode && ($notificationMode == 'true' || $notificationMode == true))
        ) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the notification object was sent for testing purposes from the CA
     *
     * @param $notification
     * @return bool
     */
    private function isTestNotification($notification)
    {
        // Get notification item from notification
        if (empty($notification['notificationItems'][0])) {
            return false;
        }

        // First item in the notification
        $notificationItem = $notification['notificationItems'][0]['NotificationRequestItem'];

        // Checks if psp reference is test
        return $this->isTestNotificationPspReference($notificationItem['pspReference']);
    }

    /**
     * If notification is a test notification from Adyen Customer Area
     *
     * @param $pspReference
     * @return bool
     */
    protected function isTestNotificationPspReference($pspReference)
    {
        if (strpos(strtolower($pspReference), 'test_') !== false
            || strpos(strtolower($pspReference), 'testnotification_') !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check if notification is a report notification
     *
     * @param $eventCode
     * @return bool
     */
    protected function isReportNotification($eventCode)
    {
        if (strpos($eventCode, 'REPORT_') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Return '[accepted]' if $acceptedMessage is empty otherwise return $acceptedMessage
     *
     * @param $acceptedMessage
     * @return string
     */
    private function returnAccepted($acceptedMessage)
    {
        if (empty($acceptedMessage)) {
            $acceptedMessage = '[accepted]';
        }

        return $acceptedMessage;
    }
}
