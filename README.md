# Adyen Payment plugin for Shopware 6
Use Adyen's plugin for Shopware 6 to offer frictionless payments online, in-app, and in-store.

## Contributing
We strongly encourage you to join us in contributing to this repository so everyone can benefit from:
* New features and functionality
* Resolved bug fixes and issues
* Any general improvements

Read our [**contribution guidelines**](https://github.com/Adyen/.github/blob/main/CONTRIBUTING.md) to find out how.

## Requirements
This plugin supports Shopware >= 6.3.1.1

Please note that versions >= 3.0.0 of this plugin only support Shopware versions >= 6.4. If you are on a lower Shopware version please use version 2.

For Shopware 5 support please see our Shopware 5 repository.

## Documentation
Please find the relevant documentation for
 - [How to start with Adyen](https://www.adyen.com/get-started)
 - [Adyen Plugin for Shopware 6](https://docs.adyen.com/plugins/shopware-6)
 - [Adyen PHP API Library](https://docs.adyen.com/development-resources/libraries#php)

## Support
If you have a feature request, or spotted a bug or a technical problem, create a GitHub issue. For other questions, 
contact our [support team](https://support.adyen.com/hc/en-us/requests/new?ticket_form_id=360000705420).

# For developers

## Integration
The plugin integrates card component (Secured Fields) using Adyen Checkout for all card payments.

### Supported payment methods
 - Credit and debit cards (non 3D secure, 3D secure 1, 3D secure 2 native)
 - Stored card payment methods (One click payment methods)
 - Affirm
 - AfterPay invoice
 - Alipay , Alipay HK
 - Amazon Pay
 - Apple Pay
 - Bancontact
 - Blik
 - Billie
 - Clearpay
 - Electronic Payment Service (EPS)
 - Gift cards
 - GiroPay
 - Google Pay
 - iDeal
 - Klarna Pay Later
 - Klarna Pay Now
 - Klarna Pay Over Time
 - Klarna Debit Risk
 - MB Way
 - MobilePay
 - Multibanco
 - Oney (3x, 4x, 6x, 10x, 12x)
 - PayBright
 - PayPal
 - PaySafeCard
 - RatePay, RatePay Direct Debit
 - SEPA Direct Debit
 - Swish
 - Trustly
 - Twint
 - Vipps
 - WeChat Pay
 - Open Banking / Pay by Bank
 - Online Banking Finland
 - Online Banking Poland

### Webhook Setup
For users with sales channels that have a storefront,
webhooks should be configured following the standard process outlined 
in the [Adyen documentation](https://docs.adyen.com/plugins/shopware-6/#set-up-webhooks).

If a user has **only** headless sales channels (i.e., channels without a storefront),
webhook support is also available. 
In this case, the webhook URL should be structured as follows:  
Website URL followed by `/store-api/adyen/notification/{salesChannelId}`.  
The `salesChannelId` parameter must be a valid sales channel ID of an active sales channel.

## API Library
This module is using the Adyen APIs Library for PHP for all (API) connections to Adyen.
<a href="https://github.com/Adyen/adyen-php-api-library" target="_blank">This library can be found here</a>

## License
MIT license. For more information, see the [LICENSE file](LICENSE).
