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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'adyen:process-webhooks', description: 'Processes scheduled webhooks')]
class ProcessWebhooksCommand extends Command
{
    /**
     * @var ProcessNotificationsHandler
     */
    protected ProcessNotificationsHandler $handler;

    public function __construct(ProcessNotificationsHandler $handler)
    {
        parent::__construct();
        $this->handler = $handler;
    }

    protected function configure(): void
    {
        $this->setDescription('Process webhook notifications.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->handler->run();
        $output->writeln('Webhook notifications have been processed.');
        return Command::SUCCESS;
    }
}
