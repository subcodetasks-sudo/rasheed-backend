<?php

namespace Modules\Project\Exceptions;

use Exception;
use Throwable;

class BusinessException extends Exception
{
    public function __construct(string $message, int $code = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
