<?php

declare(strict_types=1);

namespace GroceryRestable\Exception;

/**
 * Thrown when input data fails validation. Errors are keyed by field name.
 */
class ValidationException extends ApiException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'The given data was invalid.')
    {
        parent::__construct($message, 422, 'validation_failed', ['fields' => $errors]);
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function forField(string $field, string $error): self
    {
        return new self([$field => $error]);
    }
}
