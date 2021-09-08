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
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => '_uetsid',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => 'datadome',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => 'rl_anonymous_id',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => '_mkto_trk',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => 'rl_user_id',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => '_hjid',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => 'lastUpdatedGdpr',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => 'gdpr',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => '_fbp',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => '_ga',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => '_gid',
            'snippet_name' => 'cookie',
            'hidden' => true
        ],
        [
            'cookie' => '_gcl_au',
            'snippet_name' => 'cookie',
            'hidden' => true
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
