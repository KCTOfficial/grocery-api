<?php

declare(strict_types=1);

namespace GroceryRestable\Exception;

use RuntimeException;
use Throwable;

/**
 * Database error raised by non-PDO connections (e.g. the CodeIgniter adapter).
 * The SQLSTATE class is used to map errors to HTTP statuses.
 */
class DatabaseException extends RuntimeException
{
    public function __construct(string $message, private readonly string $sqlState = 'HY000', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getSqlState(): string
    {
        return $this->sqlState;
    }
}
