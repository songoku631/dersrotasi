<?php

declare(strict_types=1);

namespace DersRotasi\Yokatlas;

use RuntimeException;

final class YokatlasStopException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
