<?php

namespace Adyen\Shopware\Util;

class Currency
{
    /**
     * Returns the sanitized currency to use in Adyen's API calls.
     * @param mixed $amount The transaction amount, regardless of currency
     * @param string $currency The transaction currency, as a 3 letter string
     * @return int
     */
    public function sanitize($amount, ?string $currency): int
    {
        switch ($currency) {
            case "CVE":
            case "DJF":
            case "GNF":
            case "IDR":
            case "JPY":
            case "KMF":
            case "KRW":
            case "PYG":
            case "RWF":
            case "UGX":
            case "VND":
            case "VUV":
            case "XAF":
            case "XOF":
            case "XPF":
                $decimals = 0;
                break;
            case "BHD":
            case "IQD":
            case "JOD":
            case "KWD":
            case "LYD":
            case "OMR":
            case "TND":
                $decimals = 3;
                break;
            default:
                $decimals = 2;
        }

        return (int)number_format(floatval($amount), $decimals, '', '');
    }
}
