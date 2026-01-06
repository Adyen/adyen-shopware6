<?php

namespace Adyen\Shopware\Exception;

class CaptureException extends \Exception
{
    public ?string $reason = null;
}
