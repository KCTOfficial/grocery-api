<?php

declare(strict_types=1);

namespace GroceryRestable\Resource;

use GroceryRestable\Exception\ConfigurationException;

/**
 * Upload configuration of a column (Grocery CRUD set_field_upload).
 */
final class UploadField
{
    public function __construct(
        public readonly string $field,
        // Directory relative to the configured upload path, or an absolute path.
        public readonly string $path = '',
        // "gif|jpg|png"; null uses the global setting.
        public readonly ?string $allowedTypes = null,
        // "2MB"; null uses the global setting.
        public readonly ?string $maxSize = null,
    ) {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            throw new ConfigurationException('Invalid upload field name "' . $field . '".');
        }
        if (str_contains($path, "\0") || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path)) {
            throw new ConfigurationException('Upload paths must not contain "..".');
        }
    }

    /**
     * @return list<string> Lower-case extensions, or an empty list to use the global setting.
     */
    public function extensions(): array
    {
        if ($this->allowedTypes === null || trim($this->allowedTypes) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $e): string => strtolower(trim($e, " .\t")),
            preg_split('/[|,\s]+/', $this->allowedTypes) ?: [],
        )));
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'path' => $this->path, 'allowedTypes' => $this->allowedTypes, 'maxSize' => $this->maxSize];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['field'] ?? ''),
            (string) ($data['path'] ?? ''),
            isset($data['allowedTypes']) && $data['allowedTypes'] !== '' ? (string) $data['allowedTypes'] : null,
            isset($data['maxSize']) && $data['maxSize'] !== '' ? (string) $data['maxSize'] : null,
        );
    }
}
