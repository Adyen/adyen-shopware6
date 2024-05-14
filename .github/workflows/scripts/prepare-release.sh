#!/bin/bash

# Create a copy of the plugin directory
cp -r * AdyenPaymentShopware6

# Remove Shopware dependencies
composer remove shopware/core --working-dir=AdyenPaymentShopware6
composer remove shopware/storefront --working-dir=AdyenPaymentShopware6

# Remove composer.lock again and vendor directory
rm AdyenPaymentShopware6/composer.lock
rm -rf AdyenPaymentShopware6/vendor

# Install dependencies
composer install --no-dev --working-dir=AdyenPaymentShopware6

# Copy original the composer.json file
cp composer.json AdyenPaymentShopware6/.

# Zip the clean installation directory
zip -r AdyenPaymentShopware6.zip AdyenPaymentShopware6/ ; zip -d AdyenPaymentShopware6.zip __MACOSX/\* ; zip -d AdyenPaymentShopware6.zip *.git*