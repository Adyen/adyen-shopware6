<?php

namespace Adyen\Shopware\Handlers;

use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;
use Adyen\Shopware\Exception\PaymentFailedException;

class PaymentResponseHandlerResult
{
    /** @var string|null $resultCode */
    private ?string $resultCode;
    /** @var string|null $refusalReason */
    private ?string $refusalReason;
    /** @var string|null $refusalReasonCode */
    private ?string $refusalReasonCode;
    /** @var mixed $pspReference */
    private mixed $pspReference;
    /** @var array|null $action */
    private ?array $action = null;
    /** @var array|null $additionalData */
    private ?array $additionalData;
    /** @var string|null $donationToken */
    private ?string $donationToken = null;
    /** @var bool $isGiftcardOrder */
    private bool $isGiftcardOrder = false;

    /**
     * @param PaymentResponseEntity $paymentResponse
     *
     * @return PaymentResponseHandlerResult
     * @throws PaymentFailedException
     */
    public function createFromPaymentResponse(PaymentResponseEntity $paymentResponse): PaymentResponseHandlerResult
    {
        // Set result code
        $this->setResultCode($paymentResponse->getResultCode());

        $response = $paymentResponse->getResponse();

        // If response is empty return the result only with the result code
        if (empty($response)) {
            return $this;
        }

        $response = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PaymentFailedException('Invalid payment response data');
        }

        // Set pspReference if exists
        if (!empty($response['pspReference'])) {
            $this->setPspReference($response['pspReference']);
        }

        // Set action if exists
        if (!empty($response['action'])) {
            $this->setAction($response['action']);
        }

        // Set donation token if exists
        if (!empty($response['donationToken'])) {
            $this->setDonationToken($response['donationToken']);
        }

        // Set additional data if exists
        if (!empty($response['additionalData'])) {
            $this->setAdditionalData($response['additionalData']);
        }

        return $this;
    }

    /**
     * @return null|string
     */
    public function getResultCode(): ?string
    {
        return $this->resultCode;
    }

    /**
     * @param string|null $resultCode
     */
    public function setResultCode(?string $resultCode): void
    {
        $this->resultCode = $resultCode;
    }

    /**
     * @return null|string
     */
    public function getRefusalReason(): ?string
    {
        return $this->refusalReason;
    }

    /**
     * @param string|null $refusalReason
     */
    public function setRefusalReason(?string $refusalReason): void
    {
        $this->refusalReason = $refusalReason;
    }

    /**
     * @return null|string
     */
    public function getRefusalReasonCode(): ?string
    {
        return $this->refusalReasonCode;
    }

    /**
     * @param string|null $refusalReasonCode
     */
    public function setRefusalReasonCode(?string $refusalReasonCode): void
    {
        $this->refusalReasonCode = $refusalReasonCode;
    }

    /**
     * @return mixed
     */
    public function getPspReference(): mixed
    {
        return $this->pspReference;
    }

    /**
     * @param mixed $pspReference
     */
    public function setPspReference(mixed $pspReference): void
    {
        $this->pspReference = $pspReference;
    }

    /**
     * @return null|array
     */
    public function getAction(): ?array
    {
        return $this->action;
    }

    /**
     * @param array|null $action
     */
    public function setAction(?array $action): void
    {
        $this->action = $action;
    }

    /**
     * @return null|array
     */
    public function getAdditionalData(): ?array
    {
        return $this->additionalData;
    }

    /**
     * @param array|null $additionalData
     */
    public function setAdditionalData(?array $additionalData): void
    {
        $this->additionalData = $additionalData;
    }

    /**
     * @param string $donationToken
     */
    public function setDonationToken(string $donationToken): void
    {
        $this->donationToken = $donationToken;
    }

    /**
     * @return null|string
     */
    public function getDonationToken(): ?string
    {
        return $this->donationToken;
    }

    /**
     * @param bool $isGiftcardOrder
     */
    public function setIsGiftcardOrder(bool $isGiftcardOrder): void
    {
        $this->isGiftcardOrder = $isGiftcardOrder;
    }

    /**
     * @return bool
     */
    public function isGiftcardOrder(): bool
    {
        return $this->isGiftcardOrder;
    }
}
