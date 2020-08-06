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
    function __construct(CookieProviderInterface $service)
    {
        $this->originalService = $service;
    }

    // cookies can also be provided as a group
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
            /* TODO check if it can be retrieved automatically or only manually */
    ];

    /**
     * @return array
     */
    public function getCookieGroups(): array
    {
        $cookieGroups = $this->originalService->getCookieGroups();

        foreach (self::$requiredCookies as $cookieEntries) {
            $cookieGroups[0]['entries'][] = $cookieEntries;
        }

        return $cookieGroups;
    }
}
