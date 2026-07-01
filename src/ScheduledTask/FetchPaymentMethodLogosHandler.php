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
use Exception;
use Psr\Log\LoggerAwareTrait;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\HttpFoundation\Request;

class FetchPaymentMethodLogosHandler extends ScheduledTaskHandler
{
    use LoggerAwareTrait;

    /**
     * @var MediaService
     */
    private $mediaService;

    private $paymentMethodRepository;

    private $mediaRepository;
    /**
     * @var bool
     */
    private $enableUrlUploadFeature;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        MediaService $mediaService,
        $paymentMethodRepository,
        $mediaRepository,
        bool $enableUrlUploadFeature = true
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->mediaService = $mediaService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->mediaRepository = $mediaRepository;
        $this->enableUrlUploadFeature = $enableUrlUploadFeature;
    }

    public static function getHandledMessages(): iterable
    {
        return [ FetchPaymentMethodLogos::class ];
    }

    public function run(): void
    {
        if (!$this->enableUrlUploadFeature) {
            $this->logger->debug('Configuration `shopware.media.enable_url_upload_feature` is disabled.');
            return;
        }

        $context = Context::createDefaultContext();

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

            // Skip if the payment method is not registered in ShopWare.
            if ($result->getTotal() === 0) {
                continue;
            }

            $media = $this->fetchLogoFromUrl($paymentMethod);
            // Skip if the remote file is temporarily unavailable.
            if (!$media) {
                continue;
            }

            /** @var PaymentMethodEntity $paymentMethodEntity */
            $paymentMethodEntity = $result->getEntities()->first();

            $this->attachLogoToPaymentMethod(
                $media,
                $context,
                strtolower($paymentMethod->getGatewayCode()),
                $paymentMethodEntity
            );
        }
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     *
     * @return MediaFile|null
     */
    private function fetchLogoFromUrl(PaymentMethodInterface $paymentMethod): ?MediaFile
    {
        $source = sprintf(
            'https://checkoutshopper-live.adyen.com/checkoutshopper/images/logos/medium/%s',
            $paymentMethod->getLogo()
        );
        $media = null;
        try {
            $request = new Request();
            $request->query->set('extension', 'png');
            $request->request->set('url', $source);
            $request->headers->set('content-type', 'application/json');

            $media = $this->mediaService->fetchFile($request);
        } catch (Exception $exception) {
            $this->logger->warning(sprintf('The URL %s could not be reached.', $source));
        }

        return $media;
    }

    /**
     * @param MediaFile $media
     * @param Context $context
     * @param string $filename
     * @param PaymentMethodEntity $paymentMethodEntity
     *
     * @return void
     */
    private function attachLogoToPaymentMethod(
        MediaFile $media,
        Context $context,
        string $filename,
        PaymentMethodEntity $paymentMethodEntity
    ): void {
        $currentMedia = $this->mediaRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('fileName', $filename)),
            $context
        )->getEntities()->first();

        $currentMediaId = $currentMedia ? $currentMedia->getId() : null;
        if ($currentMediaId) {
            $this->mediaRepository->delete([['id' => $currentMediaId]], $context);
        }

        $newMediaId = $this->mediaService->createMediaInFolder('adyen', $context, false);
        $this->mediaService->saveMediaFile(
            $media,
            $filename,
            $context,
            'adyen',
            $newMediaId
        );

        if ($currentMediaId !== $paymentMethodEntity->getMediaId()) {
            return;
        }

        $this->paymentMethodRepository->update([
            [
                'id' => $paymentMethodEntity->getId(),
                'mediaId' => $newMediaId
            ]
        ], $context);
    }
}
