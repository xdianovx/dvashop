<?php

namespace App\Services\Media;

class VehicleImageNotFound extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, public readonly string $outcome = 'rejected')
    {
        parent::__construct($message, $code, $previous);
    }
}
