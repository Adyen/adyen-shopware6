<?php

namespace Adyen\Shopware\Util;

class Currency
{
    /**
     * Returns the sanitized currency to use in Adyen's API calls.
     *
     * @param mixed $amount The transaction amount, regardless of currency
     * @param string|null $currency The transaction currency, as a 3 letter string
     *
     * @return int
     */
    public function sanitize(mixed $amount, ?string $currency): int
    {
        $decimals = match ($currency) {
            "CVE",
            "DJF",
            "GNF",
            "IDR",
            "JPY",
            "KMF",
            "KRW",
            "PYG",
            "RWF",
            "UGX",
            "VND",
            "VUV",
            "XAF",
            "XOF",
            "XPF" => 0,
            "BHD",
            "IQD",
            "JOD",
            "KWD",
            "LYD",
            "OMR",
            "TND" => 3,
            default => 2,
        };

        return (int)number_format(floatval($amount), $decimals, '', '');
    }
}
