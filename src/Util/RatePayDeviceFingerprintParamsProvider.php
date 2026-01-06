<?php

namespace Adyen\Shopware\Util;

use Adyen\Shopware\Service\ConfigurationService;
use Symfony\Component\HttpFoundation\RequestStack;

class RatePayDeviceFingerprintParamsProvider
{
    private const TOKEN_SESSION_KEY = 'adyenRatePayDeviceFingerprintToken';

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
     * Provides fingerprint parameters
     *
     * @param string|null $salesChannelId
     *
     * @return array
     */
    public function getFingerprintParams(?string $salesChannelId = null): array
    {
        return [
            'snippetId' => $this->configurationService->getDeviceFingerprintSnippetId($salesChannelId),
            'token' => $this->getToken(),
            'location' => 'Checkout'
        ];
    }

    /**
     * Creates token, set in session and retrieves it
     *
     * @return string
     */
    public function getToken(): string
    {
        if (!$this->requestStack->getSession()->get(self::TOKEN_SESSION_KEY)) {
            $this->requestStack->getSession()->set(
                self::TOKEN_SESSION_KEY,
                // This is excluded from Sonar analysis because md5 is used to generate a unique token.
                md5($this->requestStack->getSession()->get('sessionId') . '_' . microtime())//NOSONAR
            );
        }

        return (string)$this->requestStack->getSession()->get(self::TOKEN_SESSION_KEY);
    }

    /**
     * Removes fingerprint token from session
     *
     * @return void
     */
    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::TOKEN_SESSION_KEY);
    }
}
