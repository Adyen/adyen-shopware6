<?php

declare(strict_types=1);

namespace Adyen\Shopware\Framework\Cookie;

use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

class AdyenCookieProvider implements CookieProviderInterface
{
    /**
     * @var CookieProviderInterface
     */
    private $originalService;

    /**
     * CustomCookieProvider constructor.
     *
     * @param CookieProviderInterface $service
     */
    public function __construct(CookieProviderInterface $service)
    {
        $this->originalService = $service;
    }

    private static $requiredCookies = [
        [
            'cookie' => 'JSESSIONID',
            'snippet_name' => 'adyen.required_cookie.name',
            'snippet_description' => 'adyen.required_cookie.description'
        ],
        [
            'cookie' => '_uetvid',
            "hidden" => true
        ],
        [
            'cookie' => '_uetsid',
            "hidden" => true
        ],
        [
            'cookie' => '__cfduid',
            "hidden" => true
        ],
        [
            'cookie' => '_mkto_trk',
            "hidden" => true
        ],
        [
            'cookie' => '_hjid',
            "hidden" => true
        ],
        [
            'cookie' => 'lastUpdatedGdpr',
            "hidden" => true
        ],
        [
            'cookie' => 'gdpr',
            "hidden" => true
        ],
        [
            'cookie' => '_fbp',
            "hidden" => true
        ],
        [
            'cookie' => '_ga',
            "hidden" => true
        ],
        [
            'cookie' => '_gid',
            "hidden" => true
        ],
        [
            'cookie' => '_gcl_au',
            "hidden" => true
        ]
    ];

    /**
     * @return array
     */
    public function getCookieGroups(): array
    {
        $cookieGroups = $this->originalService->getCookieGroups();

        $requiredCookieGroupKey = $this->getRequiredCookieGroupKey($cookieGroups);

        foreach (self::$requiredCookies as $cookieEntries) {
            $cookieGroups[$requiredCookieGroupKey]['entries'][] = $cookieEntries;
        }

        return $cookieGroups;
    }

    /**
     * Retrieves the required default cookie group's key in the already registered cookie groups array
     * Returns false in case it's not present in the array
     * Returns the key as int in case it's found
     *
     * @param array $cookieGroups
     * @return bool|int
     */
    private function getRequiredCookieGroupKey($cookieGroups)
    {
        $requiredCookieGroupKey = false;

        // Search the default required cookie group
        foreach ($cookieGroups as $cookieGroupKey => $cookieGroup) {
            // Loop until the default required cookie group is found in the array
            if (empty($cookieGroup['snippet_name']) || 'cookie.groupRequired' !== $cookieGroup['snippet_name']) {
                continue;
            }

            $requiredCookieGroupKey = $cookieGroupKey;

            // Stop the searching
            break;
        }

        return $requiredCookieGroupKey;
    }
}
