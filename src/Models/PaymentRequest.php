<?php declare(strict_types=1);

namespace Adyen\Shopware\Models;

use Adyen\Model\Checkout\BankAccount;
use Adyen\Model\Checkout\ObjectSerializer;
use Adyen\Model\Checkout\PaymentRequest as CheckoutPaymentRequest;

class PaymentRequest extends CheckoutPaymentRequest
{
    /**
     * @inheritDoc
     *
     * Added bankAccount
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'accountInfo' => '\Adyen\Model\Checkout\AccountInfo',
        'bankAccount' => '\Adyen\Model\Checkout\BankAccount',
        'additionalAmount' => '\Adyen\Model\Checkout\Amount',
        'additionalData' => 'array<string,string>',
        'amount' => '\Adyen\Model\Checkout\Amount',
        'applicationInfo' => '\Adyen\Model\Checkout\ApplicationInfo',
        'authenticationData' => '\Adyen\Model\Checkout\AuthenticationData',
        'billingAddress' => '\Adyen\Model\Checkout\BillingAddress',
        'browserInfo' => '\Adyen\Model\Checkout\BrowserInfo',
        'captureDelayHours' => 'int',
        'channel' => 'string',
        'checkoutAttemptId' => 'string',
        'company' => '\Adyen\Model\Checkout\Company',
        'conversionId' => 'string',
        'countryCode' => 'string',
        'dateOfBirth' => '\DateTime',
        'dccQuote' => '\Adyen\Model\Checkout\ForexQuote',
        'deliverAt' => '\DateTime',
        'deliveryAddress' => '\Adyen\Model\Checkout\DeliveryAddress',
        'deliveryDate' => '\DateTime',
        'deviceFingerprint' => 'string',
        'enableOneClick' => 'bool',
        'enablePayOut' => 'bool',
        'enableRecurring' => 'bool',
        'entityType' => 'string',
        'fraudOffset' => 'int',
        'fundOrigin' => '\Adyen\Model\Checkout\FundOrigin',
        'fundRecipient' => '\Adyen\Model\Checkout\FundRecipient',
        'industryUsage' => 'string',
        'installments' => '\Adyen\Model\Checkout\Installments',
        'lineItems' => '\Adyen\Model\Checkout\LineItem[]',
        'localizedShopperStatement' => 'array<string,string>',
        'mandate' => '\Adyen\Model\Checkout\Mandate',
        'mcc' => 'string',
        'merchantAccount' => 'string',
        'merchantOrderReference' => 'string',
        'merchantRiskIndicator' => '\Adyen\Model\Checkout\MerchantRiskIndicator',
        'metadata' => 'array<string,string>',
        'mpiData' => '\Adyen\Model\Checkout\ThreeDSecureData',
        'order' => '\Adyen\Model\Checkout\EncryptedOrderData',
        'orderReference' => 'string',
        'origin' => 'string',
        'paymentMethod' => '\Adyen\Model\Checkout\CheckoutPaymentMethod',
        'platformChargebackLogic' => '\Adyen\Model\Checkout\PlatformChargebackLogic',
        'recurringExpiry' => 'string',
        'recurringFrequency' => 'string',
        'recurringProcessingModel' => 'string',
        'redirectFromIssuerMethod' => 'string',
        'redirectToIssuerMethod' => 'string',
        'reference' => 'string',
        'returnUrl' => 'string',
        'riskData' => '\Adyen\Model\Checkout\RiskData',
        'sessionValidity' => 'string',
        'shopperEmail' => 'string',
        'shopperIP' => 'string',
        'shopperInteraction' => 'string',
        'shopperLocale' => 'string',
        'shopperName' => '\Adyen\Model\Checkout\Name',
        'shopperReference' => 'string',
        'shopperStatement' => 'string',
        'socialSecurityNumber' => 'string',
        'splits' => '\Adyen\Model\Checkout\Split[]',
        'store' => 'string',
        'storePaymentMethod' => 'bool',
        'subMerchants' => '\Adyen\Model\Checkout\SubMerchantInfo[]',
        'telephoneNumber' => 'string',
        'threeDS2RequestData' => '\Adyen\Model\Checkout\ThreeDS2RequestFields',
        'threeDSAuthenticationOnly' => 'bool',
        'trustedShopper' => 'bool'
    ];

    /**
     * @inheritdoc
     *
     * Added bankAccount
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'accountInfo' => null,
        'bankAccount' => null,
        'additionalAmount' => null,
        'additionalData' => null,
        'amount' => null,
        'applicationInfo' => null,
        'authenticationData' => null,
        'billingAddress' => null,
        'browserInfo' => null,
        'captureDelayHours' => 'int32',
        'channel' => null,
        'checkoutAttemptId' => null,
        'company' => null,
        'conversionId' => null,
        'countryCode' => null,
        'dateOfBirth' => 'date-time',
        'dccQuote' => null,
        'deliverAt' => 'date-time',
        'deliveryAddress' => null,
        'deliveryDate' => 'date-time',
        'deviceFingerprint' => null,
        'enableOneClick' => null,
        'enablePayOut' => null,
        'enableRecurring' => null,
        'entityType' => null,
        'fraudOffset' => 'int32',
        'fundOrigin' => null,
        'fundRecipient' => null,
        'industryUsage' => null,
        'installments' => null,
        'lineItems' => null,
        'localizedShopperStatement' => null,
        'mandate' => null,
        'mcc' => null,
        'merchantAccount' => null,
        'merchantOrderReference' => null,
        'merchantRiskIndicator' => null,
        'metadata' => null,
        'mpiData' => null,
        'order' => null,
        'orderReference' => null,
        'origin' => null,
        'paymentMethod' => null,
        'platformChargebackLogic' => null,
        'recurringExpiry' => null,
        'recurringFrequency' => null,
        'recurringProcessingModel' => null,
        'redirectFromIssuerMethod' => null,
        'redirectToIssuerMethod' => null,
        'reference' => null,
        'returnUrl' => null,
        'riskData' => null,
        'sessionValidity' => null,
        'shopperEmail' => null,
        'shopperIP' => null,
        'shopperInteraction' => null,
        'shopperLocale' => null,
        'shopperName' => null,
        'shopperReference' => null,
        'shopperStatement' => null,
        'socialSecurityNumber' => null,
        'splits' => null,
        'store' => null,
        'storePaymentMethod' => null,
        'subMerchants' => null,
        'telephoneNumber' => null,
        'threeDS2RequestData' => null,
        'threeDSAuthenticationOnly' => null,
        'trustedShopper' => null
    ];

    /**
     * @inheritdoc
     *
     * Added bankAccount
     *
     * @var boolean[]
     */
    protected static $openAPINullables = [
        'accountInfo' => false,
        'bankAccount' => false,
        'additionalAmount' => false,
        'additionalData' => false,
        'amount' => false,
        'applicationInfo' => false,
        'authenticationData' => false,
        'billingAddress' => false,
        'browserInfo' => false,
        'captureDelayHours' => true,
        'channel' => false,
        'checkoutAttemptId' => false,
        'company' => false,
        'conversionId' => false,
        'countryCode' => false,
        'dateOfBirth' => false,
        'dccQuote' => false,
        'deliverAt' => false,
        'deliveryAddress' => false,
        'deliveryDate' => false,
        'deviceFingerprint' => false,
        'enableOneClick' => false,
        'enablePayOut' => false,
        'enableRecurring' => false,
        'entityType' => false,
        'fraudOffset' => true,
        'fundOrigin' => false,
        'fundRecipient' => false,
        'industryUsage' => false,
        'installments' => false,
        'lineItems' => false,
        'localizedShopperStatement' => false,
        'mandate' => false,
        'mcc' => false,
        'merchantAccount' => false,
        'merchantOrderReference' => false,
        'merchantRiskIndicator' => false,
        'metadata' => false,
        'mpiData' => false,
        'order' => false,
        'orderReference' => false,
        'origin' => false,
        'paymentMethod' => false,
        'platformChargebackLogic' => false,
        'recurringExpiry' => false,
        'recurringFrequency' => false,
        'recurringProcessingModel' => false,
        'redirectFromIssuerMethod' => false,
        'redirectToIssuerMethod' => false,
        'reference' => false,
        'returnUrl' => false,
        'riskData' => false,
        'sessionValidity' => false,
        'shopperEmail' => false,
        'shopperIP' => false,
        'shopperInteraction' => false,
        'shopperLocale' => false,
        'shopperName' => false,
        'shopperReference' => false,
        'shopperStatement' => false,
        'socialSecurityNumber' => false,
        'splits' => false,
        'store' => false,
        'storePaymentMethod' => false,
        'subMerchants' => false,
        'telephoneNumber' => false,
        'threeDS2RequestData' => false,
        'threeDSAuthenticationOnly' => false,
        'trustedShopper' => false
    ];

    /**
     * @inheritDoc
     *
     * @return string[]
     */
    public static function openAPITypes()
    {
        return self::$openAPITypes;
    }

    /**
     * @inheritDoc
     *
     * @return array
     */
    public static function openAPIFormats()
    {
        return self::$openAPIFormats;
    }

    /**
     * @inheritDoc
     *
     * @return array
     */
    protected static function openAPINullables(): array
    {
        return self::$openAPINullables;
    }

    /**
     * @inheritdoc
     *
     * Added bankAccount
     *
     * @var string[]
     */
    protected static $attributeMap = [
        'accountInfo' => 'accountInfo',
        'bankAccount' => 'bankAccount',
        'additionalAmount' => 'additionalAmount',
        'additionalData' => 'additionalData',
        'amount' => 'amount',
        'applicationInfo' => 'applicationInfo',
        'authenticationData' => 'authenticationData',
        'billingAddress' => 'billingAddress',
        'browserInfo' => 'browserInfo',
        'captureDelayHours' => 'captureDelayHours',
        'channel' => 'channel',
        'checkoutAttemptId' => 'checkoutAttemptId',
        'company' => 'company',
        'conversionId' => 'conversionId',
        'countryCode' => 'countryCode',
        'dateOfBirth' => 'dateOfBirth',
        'dccQuote' => 'dccQuote',
        'deliverAt' => 'deliverAt',
        'deliveryAddress' => 'deliveryAddress',
        'deliveryDate' => 'deliveryDate',
        'deviceFingerprint' => 'deviceFingerprint',
        'enableOneClick' => 'enableOneClick',
        'enablePayOut' => 'enablePayOut',
        'enableRecurring' => 'enableRecurring',
        'entityType' => 'entityType',
        'fraudOffset' => 'fraudOffset',
        'fundOrigin' => 'fundOrigin',
        'fundRecipient' => 'fundRecipient',
        'industryUsage' => 'industryUsage',
        'installments' => 'installments',
        'lineItems' => 'lineItems',
        'localizedShopperStatement' => 'localizedShopperStatement',
        'mandate' => 'mandate',
        'mcc' => 'mcc',
        'merchantAccount' => 'merchantAccount',
        'merchantOrderReference' => 'merchantOrderReference',
        'merchantRiskIndicator' => 'merchantRiskIndicator',
        'metadata' => 'metadata',
        'mpiData' => 'mpiData',
        'order' => 'order',
        'orderReference' => 'orderReference',
        'origin' => 'origin',
        'paymentMethod' => 'paymentMethod',
        'platformChargebackLogic' => 'platformChargebackLogic',
        'recurringExpiry' => 'recurringExpiry',
        'recurringFrequency' => 'recurringFrequency',
        'recurringProcessingModel' => 'recurringProcessingModel',
        'redirectFromIssuerMethod' => 'redirectFromIssuerMethod',
        'redirectToIssuerMethod' => 'redirectToIssuerMethod',
        'reference' => 'reference',
        'returnUrl' => 'returnUrl',
        'riskData' => 'riskData',
        'sessionValidity' => 'sessionValidity',
        'shopperEmail' => 'shopperEmail',
        'shopperIP' => 'shopperIP',
        'shopperInteraction' => 'shopperInteraction',
        'shopperLocale' => 'shopperLocale',
        'shopperName' => 'shopperName',
        'shopperReference' => 'shopperReference',
        'shopperStatement' => 'shopperStatement',
        'socialSecurityNumber' => 'socialSecurityNumber',
        'splits' => 'splits',
        'store' => 'store',
        'storePaymentMethod' => 'storePaymentMethod',
        'subMerchants' => 'subMerchants',
        'telephoneNumber' => 'telephoneNumber',
        'threeDS2RequestData' => 'threeDS2RequestData',
        'threeDSAuthenticationOnly' => 'threeDSAuthenticationOnly',
        'trustedShopper' => 'trustedShopper'
    ];

    /**
     * @inheritdoc
     *
     * Added bankAccount
     *
     * @var string[]
     */
    protected static $setters = [
        'accountInfo' => 'setAccountInfo',
        'bankAccount' => 'setBankAccount',
        'additionalAmount' => 'setAdditionalAmount',
        'additionalData' => 'setAdditionalData',
        'amount' => 'setAmount',
        'applicationInfo' => 'setApplicationInfo',
        'authenticationData' => 'setAuthenticationData',
        'billingAddress' => 'setBillingAddress',
        'browserInfo' => 'setBrowserInfo',
        'captureDelayHours' => 'setCaptureDelayHours',
        'channel' => 'setChannel',
        'checkoutAttemptId' => 'setCheckoutAttemptId',
        'company' => 'setCompany',
        'conversionId' => 'setConversionId',
        'countryCode' => 'setCountryCode',
        'dateOfBirth' => 'setDateOfBirth',
        'dccQuote' => 'setDccQuote',
        'deliverAt' => 'setDeliverAt',
        'deliveryAddress' => 'setDeliveryAddress',
        'deliveryDate' => 'setDeliveryDate',
        'deviceFingerprint' => 'setDeviceFingerprint',
        'enableOneClick' => 'setEnableOneClick',
        'enablePayOut' => 'setEnablePayOut',
        'enableRecurring' => 'setEnableRecurring',
        'entityType' => 'setEntityType',
        'fraudOffset' => 'setFraudOffset',
        'fundOrigin' => 'setFundOrigin',
        'fundRecipient' => 'setFundRecipient',
        'industryUsage' => 'setIndustryUsage',
        'installments' => 'setInstallments',
        'lineItems' => 'setLineItems',
        'localizedShopperStatement' => 'setLocalizedShopperStatement',
        'mandate' => 'setMandate',
        'mcc' => 'setMcc',
        'merchantAccount' => 'setMerchantAccount',
        'merchantOrderReference' => 'setMerchantOrderReference',
        'merchantRiskIndicator' => 'setMerchantRiskIndicator',
        'metadata' => 'setMetadata',
        'mpiData' => 'setMpiData',
        'order' => 'setOrder',
        'orderReference' => 'setOrderReference',
        'origin' => 'setOrigin',
        'paymentMethod' => 'setPaymentMethod',
        'platformChargebackLogic' => 'setPlatformChargebackLogic',
        'recurringExpiry' => 'setRecurringExpiry',
        'recurringFrequency' => 'setRecurringFrequency',
        'recurringProcessingModel' => 'setRecurringProcessingModel',
        'redirectFromIssuerMethod' => 'setRedirectFromIssuerMethod',
        'redirectToIssuerMethod' => 'setRedirectToIssuerMethod',
        'reference' => 'setReference',
        'returnUrl' => 'setReturnUrl',
        'riskData' => 'setRiskData',
        'sessionValidity' => 'setSessionValidity',
        'shopperEmail' => 'setShopperEmail',
        'shopperIP' => 'setShopperIP',
        'shopperInteraction' => 'setShopperInteraction',
        'shopperLocale' => 'setShopperLocale',
        'shopperName' => 'setShopperName',
        'shopperReference' => 'setShopperReference',
        'shopperStatement' => 'setShopperStatement',
        'socialSecurityNumber' => 'setSocialSecurityNumber',
        'splits' => 'setSplits',
        'store' => 'setStore',
        'storePaymentMethod' => 'setStorePaymentMethod',
        'subMerchants' => 'setSubMerchants',
        'telephoneNumber' => 'setTelephoneNumber',
        'threeDS2RequestData' => 'setThreeDS2RequestData',
        'threeDSAuthenticationOnly' => 'setThreeDSAuthenticationOnly',
        'trustedShopper' => 'setTrustedShopper'
    ];

    /**
     * @inheritdoc
     *
     * Added bankAccount
     *
     * @var string[]
     */
    protected static $getters = [
        'accountInfo' => 'getAccountInfo',
        'bankAccount' => 'getBankAccount',
        'additionalAmount' => 'getAdditionalAmount',
        'additionalData' => 'getAdditionalData',
        'amount' => 'getAmount',
        'applicationInfo' => 'getApplicationInfo',
        'authenticationData' => 'getAuthenticationData',
        'billingAddress' => 'getBillingAddress',
        'browserInfo' => 'getBrowserInfo',
        'captureDelayHours' => 'getCaptureDelayHours',
        'channel' => 'getChannel',
        'checkoutAttemptId' => 'getCheckoutAttemptId',
        'company' => 'getCompany',
        'conversionId' => 'getConversionId',
        'countryCode' => 'getCountryCode',
        'dateOfBirth' => 'getDateOfBirth',
        'dccQuote' => 'getDccQuote',
        'deliverAt' => 'getDeliverAt',
        'deliveryAddress' => 'getDeliveryAddress',
        'deliveryDate' => 'getDeliveryDate',
        'deviceFingerprint' => 'getDeviceFingerprint',
        'enableOneClick' => 'getEnableOneClick',
        'enablePayOut' => 'getEnablePayOut',
        'enableRecurring' => 'getEnableRecurring',
        'entityType' => 'getEntityType',
        'fraudOffset' => 'getFraudOffset',
        'fundOrigin' => 'getFundOrigin',
        'fundRecipient' => 'getFundRecipient',
        'industryUsage' => 'getIndustryUsage',
        'installments' => 'getInstallments',
        'lineItems' => 'getLineItems',
        'localizedShopperStatement' => 'getLocalizedShopperStatement',
        'mandate' => 'getMandate',
        'mcc' => 'getMcc',
        'merchantAccount' => 'getMerchantAccount',
        'merchantOrderReference' => 'getMerchantOrderReference',
        'merchantRiskIndicator' => 'getMerchantRiskIndicator',
        'metadata' => 'getMetadata',
        'mpiData' => 'getMpiData',
        'order' => 'getOrder',
        'orderReference' => 'getOrderReference',
        'origin' => 'getOrigin',
        'paymentMethod' => 'getPaymentMethod',
        'platformChargebackLogic' => 'getPlatformChargebackLogic',
        'recurringExpiry' => 'getRecurringExpiry',
        'recurringFrequency' => 'getRecurringFrequency',
        'recurringProcessingModel' => 'getRecurringProcessingModel',
        'redirectFromIssuerMethod' => 'getRedirectFromIssuerMethod',
        'redirectToIssuerMethod' => 'getRedirectToIssuerMethod',
        'reference' => 'getReference',
        'returnUrl' => 'getReturnUrl',
        'riskData' => 'getRiskData',
        'sessionValidity' => 'getSessionValidity',
        'shopperEmail' => 'getShopperEmail',
        'shopperIP' => 'getShopperIP',
        'shopperInteraction' => 'getShopperInteraction',
        'shopperLocale' => 'getShopperLocale',
        'shopperName' => 'getShopperName',
        'shopperReference' => 'getShopperReference',
        'shopperStatement' => 'getShopperStatement',
        'socialSecurityNumber' => 'getSocialSecurityNumber',
        'splits' => 'getSplits',
        'store' => 'getStore',
        'storePaymentMethod' => 'getStorePaymentMethod',
        'subMerchants' => 'getSubMerchants',
        'telephoneNumber' => 'getTelephoneNumber',
        'threeDS2RequestData' => 'getThreeDS2RequestData',
        'threeDSAuthenticationOnly' => 'getThreeDSAuthenticationOnly',
        'trustedShopper' => 'getTrustedShopper'
    ];

    /**
     * @inheritDoc
     *
     * @return string[]
     */
    public static function attributeMap()
    {
        return self::$attributeMap;
    }

    /**
     * @inheritDoc
     *
     * @return string[]
     */
    public static function setters()
    {
        return self::$setters;
    }

    /**
     * @inheritDoc
     *
     * @return array
     */
    public static function getters()
    {
        return self::$getters;
    }

    /**
     * Checks if a property is nullable
     *
     * @param string $property
     * @return bool
     */
    public static function isNullable(string $property): bool
    {
        return self::openAPINullables()[$property] ?? false;
    }

    /**
     * @inheritdoc
     *
     * @param mixed[] $data Associated array of property values
     *                      initializing the model
     */
    public function __construct(array $data = null)
    {
        parent::__construct($data);

        $data = $data ?? [];
        if (self::isNullable('bankAccount') &&
            array_key_exists('bankAccount', $data) &&
            is_null($data['bankAccount'])
        ) {
            $this->openAPINullablesSetToNull[] = 'bankAccount';
        }

        $this->container['bankAccount'] = $data['bankAccount'] ?? null;
    }

    /**
     * Gets bankAccount
     *
     * @return BankAccount|null
     */
    public function getBankAccount()
    {
        return $this->container['bankAccount'];
    }

    /**
     * Sets bankAccount
     *
     * @param BankAccount|null $bankAccount bankAccount
     *
     * @return self
     */
    public function setBankAccount($bankAccount)
    {
        $this->container['bankAccount'] = $bankAccount;

        return $this;
    }

    /**
     * @inheritDoc
     *
     * Added bankAccount
     *
     */
    public function jsonSerialize()
    {
        $data = ObjectSerializer::sanitizeForSerialization($this);
        if ($this->container["bankAccount"]) {
            $data->bankAccount = $this->container["bankAccount"];
        }

        return $data;
    }
}
