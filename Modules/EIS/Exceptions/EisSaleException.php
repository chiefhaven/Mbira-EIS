<?php

namespace Modules\EIS\Exceptions;

use Exception;

class EisSaleException extends Exception
{
    public const DUPLICATE_INVOICE = 1001;

    public function __construct(
        $message = "An error occurred in EIS Sale module",
        $code = 0,
        ?Exception $previous = null,
        public readonly ?int $httpStatus = null,
        public readonly ?int $responseStatusCode = null,
        public readonly ?int $responseErrorCode = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function requiresCheckoutRollback(): bool
    {
        return ($this->responseStatusCode !== null && $this->responseStatusCode < -1)
            || ($this->responseErrorCode !== null && $this->responseErrorCode < -1);
    }
}