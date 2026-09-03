<?php

namespace Adyen\Shopware\Util;

use Adyen\Shopware\Service\ConfigurationService;
use Symfony\Component\HttpFoundation\RequestStack;

class RivertyDeviceFingerprintParamsProvider
{
    private const SESSION_ID_SESSION_KEY = 'adyenRivertyProfileTrackingSessionId';

    /**
     * @var RequestStack
     */
    protected RequestStack $requestStack;

    /**
     * @var ConfigurationService
     */
    private ConfigurationService $configurationService;

    /**
     * @param RequestStack $requestStack
     * @param ConfigurationService $configurationService
     */
    public function __construct(
        RequestStack $requestStack,
        ConfigurationService $configurationService
    ) {
        $this->requestStack = $requestStack;
        $this->configurationService = $configurationService;
    }

    /**
     * Profile tracking is only active when both the shop id and the subdomain pointing to the
     * Experian server have been configured. Without either of them the tracking tag cannot be
     * built, so no device fingerprint must be collected or sent to Adyen.
     *
     * @param string|null $salesChannelId
     *
     * @return bool
     */
    public function isProfileTrackingEnabled(?string $salesChannelId = null): bool
    {
        return $this->getShopId($salesChannelId) !== '' && $this->getSubdomain($salesChannelId) !== '';
    }

    /**
     * Provides profile tracking parameters
     *
     * @param string|null $salesChannelId
     *
     * @return array
     */
    public function getProfileTrackingParams(?string $salesChannelId = null): array
    {
        return [
            'shopId' => $this->getShopId($salesChannelId),
            'subdomain' => $this->getSubdomain($salesChannelId),
            'sessionId' => $this->getSessionId()
        ];
    }

    /**
     * Creates the session id, sets it in the session and retrieves it
     *
     * @return string
     */
    public function getSessionId(): string
    {
        if (!$this->requestStack->getSession()->get(self::SESSION_ID_SESSION_KEY)) {
            $this->requestStack->getSession()->set(
                self::SESSION_ID_SESSION_KEY,
                // This is excluded from Sonar analysis because md5 is used to generate a unique id.
                md5($this->requestStack->getSession()->get('sessionId') . '_' . microtime())//NOSONAR
            );
        }

        return (string)$this->requestStack->getSession()->get(self::SESSION_ID_SESSION_KEY);
    }

    /**
     * Removes the profile tracking session id from the session
     *
     * @return void
     */
    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_ID_SESSION_KEY);
    }

    /**
     * @param string|null $salesChannelId
     *
     * @return string
     */
    private function getShopId(?string $salesChannelId = null): string
    {
        return trim((string)$this->configurationService->getRivertyProfileTrackingShopId($salesChannelId));
    }

    /**
     * @param string|null $salesChannelId
     *
     * @return string
     */
    private function getSubdomain(?string $salesChannelId = null): string
    {
        return trim((string)$this->configurationService->getRivertyProfileTrackingSubdomain($salesChannelId));
    }
}
