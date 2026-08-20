<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public const REASON_ERROR = 'error';

    public const REASON_UNREACHABLE = 'unreachable';

    public const REASON_REQUEST_FAILED = 'request_failed';

    public const REASON_INVALID_RESPONSE = 'invalid_response';

    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        string $message,
        public readonly string $reason = self::REASON_ERROR,
        public readonly ?array $rawResponse = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public static function unavailable(
        string $message,
        string $reason = self::REASON_ERROR,
        ?array $rawResponse = null,
    ): self {
        return new self($message, $reason, $rawResponse);
    }

    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public static function invalidResponse(string $message, ?array $rawResponse = null): self
    {
        return new self($message, self::REASON_INVALID_RESPONSE, $rawResponse);
    }
}
