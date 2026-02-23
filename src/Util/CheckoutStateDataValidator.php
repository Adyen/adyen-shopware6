<?php

namespace Adyen\Shopware\Util;

class CheckoutStateDataValidator
{
    protected array $stateDataRootKeys = [
        'paymentMethod',
        'billingAddress',
        'deliveryAddress',
        'riskData',
        'shopperName',
        'dateOfBirth',
        'telephoneNumber',
        'shopperEmail',
        'countryCode',
        'socialSecurityNumber',
        'browserInfo',
        'installments',
        'storePaymentMethod',
        'conversionId',
        'paymentData',
        'details',
        'origin',
        'billieData'
    ];

    /**
     * @param array $stateData
     *
     * @return array
     */
    public function getValidatedAdditionalData(array $stateData): array
    {
        // Get validated state data array
        if (!empty($stateData)) {
            $stateData = DataArrayValidator::getArrayOnlyWithApprovedKeys($stateData, $this->stateDataRootKeys);
        }
        return $stateData;
    }
}
