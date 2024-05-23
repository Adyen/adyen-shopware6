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

use Adyen\Shopware\ScheduledTask\ScheduleNotificationsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'adyen:schedule-webhooks', description: 'Schedules Adyen webhooks')]
class ScheduleWebhooksCommand extends Command
{
    /**
     * @var ScheduleNotificationsHandler
     */
    protected ScheduleNotificationsHandler $handler;

    /**
     * @param ScheduleNotificationsHandler $handler
     */
    public function __construct(ScheduleNotificationsHandler $handler)
    {
        parent::__construct();
        $this->handler = $handler;
    }

    protected function configure(): void
    {
        $this->setDescription('Schedule webhook notifications');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->handler->run();
        $output->writeln('Webhook notifications have been scheduled');
        return Command::SUCCESS;
    }
}
