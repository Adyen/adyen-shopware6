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

namespace Adyen\Shopware\ScheduledTask;

use Adyen\Shopware\PaymentMethods\PaymentMethods;
use Adyen\Shopware\Service\ConfigurationService;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Filesystem\Filesystem;

class FetchPaymentMethodLogosHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    /**
     * @var ConfigurationService
     */
    private $configurationService;
    /**
     * @var Filesystem
     */
    private $filesystem;

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        ConfigurationService $configurationService,
        Filesystem $filesystem
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->configurationService = $configurationService;
        $this->filesystem = $filesystem;
    }

    public static function getHandledMessages(): iterable
    {
        return [ FetchPaymentMethodLogos::class ];
    }

    public function run(): void
    {
        $environment = $this->configurationService->getEnvironment();
        $logosDirectory = __DIR__ . '/../Resources/public/images/logos/';

        foreach (PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $logo = (new $paymentMethod())->getLogo();
            $source = sprintf(
                'https://checkoutshopper-%s.adyen.com/checkoutshopper/images/logos/medium/%s',
                $environment,
                $logo
            );
            $target = $logosDirectory . $logo;
            try {
                $this->filesystem->copy($source, $target);
            } catch (Exception $exception) {
                $this->logger->notice("Unable to update {$logo}: {$exception->getMessage()}");
                continue;
            }
        }
    }
}
