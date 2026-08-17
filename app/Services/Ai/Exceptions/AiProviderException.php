<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public static function unavailable(string $message): self
    {
        return new self($message);
    }

    public static function invalidResponse(string $message): self
    {
        return new self($message);
    }
}
