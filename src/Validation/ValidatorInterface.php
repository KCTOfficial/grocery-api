<?php

declare(strict_types=1);

namespace GroceryRestable\Validation;

interface ValidatorInterface
{
    /**
     * Validate $data against CodeIgniter style rule strings.
     *
     * Fields that are absent from $data are only checked by "required"-like rules,
     * which allows partial updates (PATCH).
     *
     * @param array<string, mixed> $data
     * @param array<string, array{rules: string, label: string|null}> $rules
     * @return array<string, string> Error message per field (empty when valid).
     */
    public function validate(array $data, array $rules): array;
}
