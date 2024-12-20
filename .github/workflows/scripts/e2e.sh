#!/bin/bash
set -euo pipefail

# Checkout E2E tests
cd /tmp/adyen-integration-tools-tests;
# Setup environment
rm -rf package-lock.json;
npm i;

# Run tests
npm run test:ci:shopware
