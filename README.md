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

### Webhook Setup
For users with sales channels that have a storefront,
webhooks should be configured following the standard process outlined 
in the [Adyen documentation](https://docs.adyen.com/plugins/shopware-6/#set-up-webhooks).

If a user has **only** headless sales channels (i.e., channels without a storefront),
webhook support is also available. 
In this case, the webhook URL should be structured as follows:  
Sales channel domain URL followed by `/store-api/adyen/notification/{salesChannelId}`.  
The `salesChannelId` parameter must be a valid sales channel ID of an active sales channel.

## API Library
This module is using the Adyen APIs Library for PHP for all (API) connections to Adyen.
<a href="https://github.com/Adyen/adyen-php-api-library" target="_blank">This library can be found here</a>

## License
MIT license. For more information, see the [LICENSE file](LICENSE).
