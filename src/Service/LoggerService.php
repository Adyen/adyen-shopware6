<?php
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

namespace Adyen\Shopware\Service;

use Shopware\Core\Framework\Log\LoggerFactory;

class LoggerService extends \Monolog\Logger
{
    const LOG_DIR = 'adyen/';
    const NAME = 'ADYEN';
    const ADYEN_API = 201;
    const ADYEN_RESULT = 202;
    const ADYEN_NOTIFICATION = 203;
    const ADYEN_CRONJOB = 204;
    const ADYEN_ERROR = 400;

    private static $adyenHandlers = array(
        self::DEBUG => array(
            'level' => self::DEBUG,
            'filePrefix' => 'debug'
        ),
        self::INFO => array(
            'level' => self::INFO,
            'filePrefix' => 'info'
        ),
        self::ADYEN_API => array(
            'level' => self::ADYEN_API,
            'filePrefix' => 'adyen_api'
        ),
        self::ADYEN_RESULT => array(
            'level' => self::ADYEN_RESULT,
            'filePrefix' => 'adyen_result'
        ),
        self::ADYEN_NOTIFICATION => array(
            'level' => self::ADYEN_NOTIFICATION,
            'filePrefix' => 'adyen_notification'
        ),
        self::ADYEN_CRONJOB => array(
            'level' => self::ADYEN_CRONJOB,
            'filePrefix' => 'adyen_cronjob'
        ),
        self::NOTICE => array(
            'level' => self::NOTICE,
            'filePrefix' => 'notice'
        ),
        self::WARNING => array(
            'level' => self::WARNING,
            'filePrefix' => 'warning'
        ),
        self::ADYEN_ERROR => array(
            'level' => self::ADYEN_ERROR,
            'filePrefix' => 'adyen_error'
        ),
    );

    /**
     * Logging levels from syslog protocol defined in RFC 5424
     * Overrule the default to add Adyen specific loggers to log into separate files
     *
     * @var array $levels Logging levels
     */
    protected static $levels = array(
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::ADYEN_API => 'ADYEN_API',
        self::ADYEN_RESULT => 'ADYEN_RESULT',
        self::ADYEN_NOTIFICATION => 'ADYEN_NOTIFICATION',
        self::ADYEN_CRONJOB => 'ADYEN_CRONJOB',
        self::NOTICE => 'NOTICE',
        self::WARNING => 'WARNING',
        self::ADYEN_ERROR => 'ADYEN_ERROR',
        self::CRITICAL => 'CRITICAL',
        self::ALERT => 'ALERT',
        self::EMERGENCY => 'EMERGENCY',
    );

    /**
     * @var LoggerFactory
     */
    private $loggerFactory;

    /**
     * LoggerService constructor.
     *
     * @param LoggerFactory $loggerFactory
     */
    public function __construct(
        LoggerFactory $loggerFactory
    ) {
        $this->loggerFactory = $loggerFactory;
        $this->registerAdyenLogHandlers();
        parent::__construct(self::NAME);
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function addAdyenAPI($message, array $context = array())
    {
        return self::$adyenHandlers[self::ADYEN_API]['logger']->info($message);
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function addAdyenResult($message, array $context = array())
    {
        return self::$adyenHandlers[self::ADYEN_RESULT]['logger']->info($message);
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function addAdyenError($message, array $context = array())
    {
        return self::$adyenHandlers[self::ERROR]['logger']->error($message);
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function addAdyenNotification($message, array $context = array())
    {
        return self::$adyenHandlers[self::ADYEN_NOTIFICATION]['logger']->info($message);
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function addAdyenCronjob($message, array $context = array())
    {
        return self::$adyenHandlers[self::ADYEN_CRONJOB]['logger']->info($message);
    }

    /**
     * @throws CommandException
     */
    private function registerAdyenLogHandlers()
    {
        foreach (self::$adyenHandlers as $key => $adyenHandler) {
            self::$adyenHandlers[$key]['logger'] =
                $this->loggerFactory->createRotating(self::LOG_DIR . $adyenHandler['filePrefix'], 0);
        }
    }
}
