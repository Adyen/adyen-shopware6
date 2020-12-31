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

use Adyen\Shopware\PaymentMethods\PaymentMethodInterface;
use Adyen\Shopware\PaymentMethods\PaymentMethods;
use Adyen\Shopware\Service\ConfigurationService;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\HttpFoundation\Request;

class FetchPaymentMethodLogosHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    /**
     * @var ConfigurationService
     */
    private $configurationService;
    /**
     * @var MediaService
     */
    private $mediaService;
    /**
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;
    /**
     * @var EntityRepositoryInterface
     */
    private $mediaRepository;

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        ConfigurationService $configurationService,
        MediaService $mediaService,
        EntityRepositoryInterface $paymentMethodRepository,
        EntityRepositoryInterface $mediaRepository
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->configurationService = $configurationService;
        $this->mediaService = $mediaService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->mediaRepository = $mediaRepository;
    }

    public static function getHandledMessages(): iterable
    {
        return [ FetchPaymentMethodLogos::class ];
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $environment = $this->configurationService->getEnvironment();
        foreach (PaymentMethods::PAYMENT_METHODS as $identifier) {
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = new $identifier();

            // Look up corresponding payment_method entity.
            $result = $this->paymentMethodRepository->search(
                (new Criteria())->addFilter(new EqualsFilter(
                    'handlerIdentifier',
                    $paymentMethod->getPaymentHandler()
                )),
                $context
            );
            if ($result->getTotal() === 0) {
                continue;
            }
            /** @var PaymentMethodEntity $paymentMethodEntity */
            $paymentMethodEntity = $result->getEntities()->first();

            // Delete old associated media.
            $mediaId = $paymentMethodEntity->getMediaId();
            if ($mediaId) {
                $this->mediaRepository->delete([['id' => $mediaId]], $context);
            }

            $this->fetchAndAttachLogo($paymentMethod, $paymentMethodEntity->getId(), $context, $environment);
        }
    }

    private function fetchAndAttachLogo (
        PaymentMethodInterface $paymentMethod,
        string $paymentMethodEntityId,
        Context $context,
        string $environment
    ): void {
        $source = sprintf(
            'https://checkoutshopper-%s.adyen.com/checkoutshopper/images/logos/medium/%s',
            $environment,
            $paymentMethod->getLogo()
        );
        $request = new Request();
        $request->query->set('extension', 'png');
        $request->request->set('url', $source);
        $request->headers->set('content-type', 'application/json');

        $media = $this->mediaService->fetchFile($request);
        $mediaId = $this->mediaService->createMediaInFolder('adyen', $context, false);
        $this->mediaService->saveMediaFile(
            $media,
            strtolower($paymentMethod->getGatewayCode()),
            $context, 'adyen', $mediaId
        );

        $this->paymentMethodRepository->update([
            [
                'id' => $paymentMethodEntityId,
                'mediaId' => $mediaId
            ]
        ], $context);
    }
}
