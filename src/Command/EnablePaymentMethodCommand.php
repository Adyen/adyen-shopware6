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

use Adyen\Shopware\Handlers\Command\EnablePaymentMethodHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnablePaymentMethodCommand extends Command
{
    protected static $defaultName = 'adyen:payment-method:enable';

    /**
     * @var EnablePaymentMethodHandler
     */
    protected $handler;

    public function __construct(EnablePaymentMethodHandler $handler)
    {
        parent::__construct();
        $this->handler = $handler;
    }

    protected function configure()
    {
        $this->setDescription('Finds the payment method according to given PM handler and enables it');
        $this->addArgument(
            'paymentMethodHandlerIdentifier',
            InputArgument::REQUIRED,
            'Fully qualified payment method handler identifier'
        );
        $this->addUsage('all');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $paymentMethodHandlerIdentifier = $input->getArgument('paymentMethodHandlerIdentifier');
            $this->handler->run($paymentMethodHandlerIdentifier);
            $message = 'Payment method is enabled successfully.';
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        $output->writeln($message);
        return 0;
    }
}
