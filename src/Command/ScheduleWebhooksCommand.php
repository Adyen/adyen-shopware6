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
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Command;

use Adyen\Shopware\ScheduledTask\ProcessNotificationsHandler;
use Adyen\Shopware\ScheduledTask\ScheduleNotificationsHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScheduleWebhooksCommand extends Command
{
    protected static $defaultName = 'adyen:schedule-webhooks';

    /**
     * @var ProcessNotificationsHandler
     */
    protected $handler;

    public function __construct(ScheduleNotificationsHandler $handler)
    {
        parent::__construct();
        $this->handler = $handler;
    }

    protected function configure()
    {
        $this->setDescription('Schedule webhook notifications.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->handler->run();
        $output->writeln('Webhook notifications have been scheduled.');
        return 0;
    }
}
