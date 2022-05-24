<?php

namespace Adyen\Shopware\Handlers;

use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;
use Adyen\Shopware\Exception\PaymentFailedException;

class PaymentResponseHandlerResult
{
    private $resultCode;
    private $refusalReason;
    private $refusalReasonCode;
    private $pspReference;
    private $paymentReference;
    private $action;
    private $additionalData;

    /**
     * @param PaymentResponseEntity $paymentResponse
     * @return PaymentResponseHandlerResult
     * @throws PaymentFailedException
     */
    public function createFromPaymentResponse(PaymentResponseEntity $paymentResponse): PaymentResponseHandlerResult
    {
        $this->setResultCode($paymentResponse->getResultCode());
        $this->setRefusalReason($paymentResponse->getRefusalReason());
        $this->setRefusalReasonCode($paymentResponse->getRefusalReasonCode());
        $this->setPaymentReference($paymentResponse->getPaymentReference());

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

        // Set additional data if exists
        if (!empty($response['additionalData'])) {
            $this->setAdditionalData($response['additionalData']);
        }

        return $this;
    }

    /**
     * @return null|string
     */
    public function getResultCode()
    {
        return $this->resultCode;
    }

    /**
     * @param null|string $resultCode
     */
    public function setResultCode($resultCode): void
    {
        $this->resultCode = $resultCode;
    }

    /**
     * @return null|string
     */
    public function getRefusalReason() : ?string
    {
        return $this->refusalReason;
    }

    /**
     * @param null|string $refusalReason
     */
    public function setRefusalReason($refusalReason): void
    {
        $this->refusalReason = $refusalReason;
    }

    /**
     * @return null|string
     */
    public function getRefusalReasonCode() : ?string
    {
        return $this->refusalReasonCode;
    }

    /**
     * @param null|string $refusalReasonCode
     */
    public function setRefusalReasonCode($refusalReasonCode): void
    {
        $this->refusalReasonCode = $refusalReasonCode;
    }

    /**
     * @return mixed
     */
    public function getPspReference()
    {
        return $this->pspReference;
    }

    /**
     * @param mixed $pspReference
     */
    public function setPspReference($pspReference): void
    {
        $this->pspReference = $pspReference;
    }

    /**
     * @return null|array
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * @param null|array $action
     */
    public function setAction($action): void
    {
        $this->action = $action;
    }

    /**
     * @return null|array
     */
    public function getAdditionalData()
    {
        return $this->additionalData;
    }

    /**
     * @param null|array $additionalData
     */
    public function setAdditionalData($additionalData): void
    {
        $this->additionalData = $additionalData;
    }

    /**
     * @return null|string
     */
    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    /**
     * @param null|string $paymentReference
     */
    public function setPaymentReference(?string $paymentReference): void
    {
        $this->paymentReference = $paymentReference;
    }
}
