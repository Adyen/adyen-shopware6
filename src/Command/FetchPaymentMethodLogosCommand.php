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

namespace Adyen\Shopware\Command;

use Adyen\Shopware\ScheduledTask\FetchPaymentMethodLogosHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FetchPaymentMethodLogosCommand extends Command
{
    protected static $defaultName = 'adyen:fetch-logos';

    /**
     * @var FetchPaymentMethodLogosHandler
     */
    protected $handler;

    public function __construct(FetchPaymentMethodLogosHandler $handler)
    {
        parent::__construct();
        $this->handler = $handler;
    }

    protected function configure(): void
    {
        $this->setDescription('Fetch and update logos for Adyen payment methods.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->handler->run();
        $output->writeln('All available logos have been updated.');
        return Command::SUCCESS;
    }
}
