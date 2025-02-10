#!/bin/bash

# Create a copy of the plugin directory
cd ..
cp -r adyen-shopware6 AdyenPaymentShopware6

# Remove Shopware dependencies
composer remove shopware/core --working-dir=./AdyenPaymentShopware6
composer remove shopware/storefront --working-dir=./AdyenPaymentShopware6

# Remove composer.lock again and vendor directory
rm AdyenPaymentShopware6/composer.lock
rm -rf AdyenPaymentShopware6/vendor

# Install dependencies
composer install --no-dev --working-dir=./AdyenPaymentShopware6

# Copy original the composer.json file
cp adyen-shopware6/composer.json AdyenPaymentShopware6/.

# Zip the plugin directory
zip -r AdyenPaymentShopware6.zip AdyenPaymentShopware6/ ;

# Cleanup the zip installation
zip -d AdyenPaymentShopware6.zip __MACOSX/\* ; zip -d AdyenPaymentShopware6.zip *.git* ;
zip -d AdyenPaymentShopware6.zip */Dockerfile

# Move the zip file to plugin folder
mv AdyenPaymentShopware6.zip adyen-shopware6

# Go back to workflow's root directory
cd adyen-shopware6
