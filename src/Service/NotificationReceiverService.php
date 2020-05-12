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
use Adyen\Shopware\Exception\MerchantAccountCodeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Adyen\AdyenException;
use Adyen\Util\HmacSignature;

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
     * NotificationReceiverService constructor.
     *
     * @param ConfigurationService $configurationService
     * @param HmacSignature $hmacSignature
     */
    public function __construct(
        ConfigurationService $configurationService,
        HmacSignature $hmacSignature
    ) {
        $this->configurationService = $configurationService;
        $this->hmacSignature = $hmacSignature;
    }

    /**
     * @param $notificationItems
     * @return string|JsonResponse
     * @throws AdyenException
     * @throws AuthenticationException
     * @throws AuthorizationException
     * @throws HMACKeyValidationException
     * @throws MerchantAccountCodeException
     */
    public function process($notificationItems)
    {
        return new JsonResponse($this->configurationService->getApiKey());

        // Validate if notification is not empty
        if (empty($notificationItems)) {
            $message = 'Notification is empty';
            //TODO log notification message $logger->addAdyenNotification($message);
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => $message
                ]
            );
        }

        //TODO get plugin mode
        $pluginMode = 'test';

        if (!empty($notificationItems['live']) && $this->validateNotificationMode($notificationItems['live'], $pluginMode)) {
            $acceptedMessage = '[accepted]';

            foreach ($notificationItems['notificationItems'] as $notificationItem) {
                if (!$this->processNotification($notificationItem['NotificationRequestItem'])) {
                    throw new AuthorizationException();
                }
            }

            $cronCheckTest = $notificationItems['notificationItems'][0]['NotificationRequestItem']['pspReference'];

            // Run the query for checking unprocessed notifications, do this only for test notifications coming from
            // the Adyen Customer Area
            if ($this->isTestNotification($cronCheckTest)) {
                $unprocessedNotifications = $this->adyenNotification->getNumberOfUnprocessedNotifications();
                if ($unprocessedNotifications > 0) {
                    $acceptedMessage .= "\nYou have $unprocessedNotifications unprocessed notifications.";
                }
            }
            //TODO log notification message $logger->addAdyenNotification('The result is accepted');

            return $this->returnAccepted($acceptedMessage);
        } else {
            $message = 'Mismatch between Live/Test modes of Shopware store and the Adyen platform';
            //TODO log notification message $logger->addAdyenNotification($message);
            return new JsonResponse(
                array(
                    'success' => false,
                    'message' => $message
                )
            );
        }
    }

    /**
     * Authentication of the notification
     *
     * @param $notification
     * @param $merchantAccount
     * @param $hmacKey
     * @param $userName
     * @param $password
     * @return bool
     * @throws AdyenException
     * @throws AuthenticationException
     * @throws HMACKeyValidationException
     * @throws MerchantAccountCodeException
     */
    protected function isAuthenticated($notification, $merchantAccount, $hmacKey, $userName, $password)
    {
        // Check if the notification is a test notification
        $isTestNotification = $this->isTestNotification($notification['pspReference']);

        // Validate if notification or configuration merchant account value is missing
        if (empty($notification['merchantAccountCode']) || empty($merchantAccount)) {
            if ($isTestNotification) {
                $message = 'MerchantAccountCode or merchant account configuration is empty.';
                throw new MerchantAccountCodeException($message);
            }

            return false;
        }

        // Validate if username and password is sent
        if ((!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']))) {
            if ($isTestNotification) {
                $message = 'PHP_AUTH_USER or PHP_AUTH_PW is not in the request.';
                throw new AuthenticationException($message);
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

        // Validate the username and password
        $usernameCmp = hash_equals($userName, $_SERVER['PHP_AUTH_USER']);
        $passwordCmp = hash_equals($password, $_SERVER['PHP_AUTH_PW']);
        if ($usernameCmp === false || $passwordCmp === false) {
            if ($isTestNotification) {
                $message = 'Username (PHP_AUTH_USER) and\or password (PHP_AUTH_PW) are not the same as ' .
                    ' configuration settings';
                throw new AuthenticationException($message);
            }

            return false;
        }

        // Notification is authenticated
        return true;
    }

    /**
     * Save notification into the database for cron job to execute notification
     *
     * @param $notification
     * @return bool
     * @throws AuthenticationException
     * @throws HMACKeyValidationException
     * @throws MerchantAccountCodeException
     * @throws AdyenException
     */
    protected function processNotification($notification)
    {
        // TODO get these configuration values
        $merchantAccount = $this->systemConfigService->get('AdyenPayment.config.merchantAccount');
        $hmacKey = $this->systemConfigService->get('AdyenPayment.config.merchantAccount');
        $userName = $this->systemConfigService->get('AdyenPayment.config.notificationUsername');
        $password = $this->systemConfigService->get('AdyenPayment.config.notificationPassword');

        // validate the notification
        if ($this->isAuthenticated($notification, $merchantAccount, $hmacKey, $userName, $password)) {
            // log the notification
            //TODO log notification message $logger->addAdyenNotification('The content of the notification item is: ' . print_r($notification, 1));

            // skip report notifications
            if ($this->isReportNotification($notification['eventCode'])) {
                //TODO log notification message $logger->addAdyenNotification('Notification is a REPORT notification from Adyen Customer Area');
                return true;
            }

            // check if notification already exists
            if (!$this->isTestNotification($notification['pspReference']) /* && TODO add isDuplicate function !$this->adyenNotification->isDuplicate(
                    $notification
                )*/) {
                // TODO insert notifications
                //$this->adyenNotification->insertNotification($notification);
                return true;
            } else {
                // duplicated so do nothing but return accepted to Adyen
                //TODO log notification message $logger->addAdyenNotification('Notification is a TEST notification from Adyen Customer Area');
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
     * If notification is a test notification from Adyen Customer Area
     *
     * @param $pspReference
     * @return bool
     */
    protected function isTestNotification($pspReference)
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
