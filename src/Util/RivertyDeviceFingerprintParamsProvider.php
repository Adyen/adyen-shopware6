<?php

namespace Adyen\Shopware\Util;

use Adyen\Shopware\Service\ConfigurationService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

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
     * Creates the session id, sets it in the session and retrieves it. Returns an empty string on
     * requests without a session, where no tracking tag can be rendered anyway.
     *
     * @return string
     */
    public function getSessionId(): string
    {
        $session = $this->getSession();

        if (is_null($session)) {
            return '';
        }

        if (!$session->get(self::SESSION_ID_SESSION_KEY)) {
            $session->set(self::SESSION_ID_SESSION_KEY, bin2hex(random_bytes(16)));
        }

        return (string)$session->get(self::SESSION_ID_SESSION_KEY);
    }

    /**
     * Retrieves the profile tracking session id without creating one. A missing id means the
     * tracking tag was never rendered, so there is no fingerprint to report.
     *
     * @return string|null
     */
    public function getExistingSessionId(): ?string
    {
        $sessionId = $this->getSession()?->get(self::SESSION_ID_SESSION_KEY);

        return empty($sessionId) ? null : (string)$sessionId;
    }

    /**
     * Removes the profile tracking session id from the session
     *
     * @return void
     */
    public function clear(): void
    {
        $this->getSession()?->remove(self::SESSION_ID_SESSION_KEY);
    }

    /**
     * Sales channels without a storefront (and any other request without a session) have nowhere to
     * keep the profile tracking id, so the session is optional here.
     *
     * @return SessionInterface|null
     */
    private function getSession(): ?SessionInterface
    {
        $requests = [$this->requestStack->getCurrentRequest(), $this->requestStack->getMainRequest()];

        foreach ($requests as $request) {
            if (!is_null($request) && $request->hasSession()) {
                return $request->getSession();
            }
        }

        return null;
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
