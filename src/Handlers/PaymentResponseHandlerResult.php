<?php

namespace Adyen\Shopware\Handlers;

use Adyen\Shopware\Entity\PaymentResponse\PaymentResponseEntity;

class PaymentResponseHandlerResult
{
    private $resultCode;
    private $pspReference;
    private $action;
    private $additionalData;

    /**
     * @param PaymentResponseEntity $paymentResponse
     */
    public function createFromPaymentResponse($paymentResponse)
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
            //TODO error handling
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
}
