<?php

namespace App\Exceptions;

use App\Enums\GatewayErrorCode;
use Exception;

class GatewayException extends Exception
{
    public function __construct(
        public readonly GatewayErrorCode $errorCode,
        ?string $message = null,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message ?? $errorCode->message());
    }
}
