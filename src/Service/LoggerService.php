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

use Adyen\Shopware\Exception\CommandException;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

class LoggerService extends \Monolog\Logger
{
    const SHOPWARE_LOG_PATH = '../var/log/'; //TODO prefix with shopware webroot path or use log dir const
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
            'fileName' => 'debug.log'
        ),
        self::INFO => array(
            'level' => self::INFO,
            'fileName' => 'info.log'
        ),
        self::ADYEN_API => array(
            'level' => self::ADYEN_API,
            'fileName' => 'adyen_api.log'
        ),
        self::ADYEN_RESULT => array(
            'level' => self::ADYEN_RESULT,
            'fileName' => 'adyen_result.log'
        ),
        self::ADYEN_NOTIFICATION => array(
            'level' => self::ADYEN_NOTIFICATION,
            'fileName' => 'adyen_notification.log'
        ),
        self::ADYEN_CRONJOB => array(
            'level' => self::ADYEN_CRONJOB,
            'fileName' => 'adyen_cronjob.log'
        ),
        self::NOTICE => array(
            'level' => self::NOTICE,
            'fileName' => 'notice.log'
        ),
        self::WARNING => array(
            'level' => self::WARNING,
            'fileName' => 'warning.log'
        ),
        self::ERROR => array(
            'level' => self::ERROR,
            'fileName' => 'error.log'
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

    public function __construct() {
        parent::__construct(self::NAME);
        $this->registerAdyenLogHandlers();
        $this->pushProcessor((new PsrLogMessageProcessor()));
    }

    /**
     * Retrieve Adyen log path
     * If it doesn't exist yet then also creates it
     *
     * @return string
     * @throws CommandException
     */
    private function getAdyenLogPath()
    {
        $path = self::SHOPWARE_LOG_PATH . self::LOG_DIR;

        if (!file_exists($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new CommandException('Creating the Adyen log folder failed');
            }
        }

        return $path;
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function adyenAPI($message, array $context = array())
    {
        return $this->addRecord(static::ADYEN_API, $message, $context);
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function adyenResult($message, array $context = array())
    {
        return $this->addRecord(static::ADYEN_RESULT, $message, $context);
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function adyenNotification($message, array $context = array())
    {
        return $this->addRecord(static::ADYEN_NOTIFICATION, $message, $context);
    }

    /**
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function adyenCronjob($message, array $context = array())
    {
        return $this->addRecord(static::ADYEN_CRONJOB, $message, $context);
    }

    /**
     * @throws CommandException
     */
    private function registerAdyenLogHandlers()
    {
        $adyenLogPath = $this->getAdyenLogPath();
        foreach (self::$adyenHandlers as $adyenHandler) {
            $this->pushHandler(new StreamHandler(
                $adyenLogPath . '/' . $adyenHandler['fileName'],
                $adyenHandler['level'],
                false
            ));
        }
    }
}
