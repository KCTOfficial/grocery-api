<?php

declare(strict_types=1);

namespace GroceryRestable\Exception;

use RuntimeException;
use Throwable;

/**
 * An error that is safe to return to API clients.
 *
 * The message and details of an ApiException are always sent to the client,
 * so never put secrets, SQL or stack traces in them.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly string $errorCode = 'bad_request',
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function badRequest(string $message, array $details = []): static
    {
        return new static($message, 400, 'bad_request', $details);
    }

    public static function unauthorized(string $message = 'Authentication required.'): static
    {
        return new static($message, 401, 'unauthorized');
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): static
    {
        return new static($message, 403, 'forbidden');
    }

    public static function notFound(string $message = 'Resource not found.'): static
    {
        return new static($message, 404, 'not_found');
    }

    public static function methodNotAllowed(string $message = 'Method not allowed.'): static
    {
        return new static($message, 405, 'method_not_allowed');
    }

    public static function conflict(string $message, array $details = []): static
    {
        return new static($message, 409, 'conflict', $details);
    }

    public static function payloadTooLarge(string $message): static
    {
        return new static($message, 413, 'payload_too_large');
    }

    public static function unsupportedMediaType(string $message): static
    {
        return new static($message, 415, 'unsupported_media_type');
    }

    public static function tooManyRequests(string $message): static
    {
        return new static($message, 429, 'too_many_requests');
    }
}
