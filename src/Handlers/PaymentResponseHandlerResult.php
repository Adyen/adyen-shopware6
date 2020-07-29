<?php

namespace Adyen\Shopware\Handlers;

class PaymentResponseHandlerResult
{
    private $resultCode;
    private $pspReference;
    private $action;
    private $additionalData;

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
