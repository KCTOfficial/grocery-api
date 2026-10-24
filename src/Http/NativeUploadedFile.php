<?php

declare(strict_types=1);

namespace GroceryRestable\Http;

use RuntimeException;

/**
 * Uploaded file coming from PHP's $_FILES.
 */
final class NativeUploadedFile implements UploadedFileInterface
{
    /**
     * @param bool $fromSapi When false (tests / CLI) the file is moved with rename() instead of move_uploaded_file().
     */
    public function __construct(
        private readonly string $tempPath,
        private readonly string $clientFilename,
        private readonly string $clientMediaType,
        private readonly int $size,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $fromSapi = true,
    ) {
    }

    public function getClientFilename(): string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): string
    {
        return $this->clientMediaType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getTempPath(): string
    {
        return $this->tempPath;
    }

    public function moveTo(string $targetPath): void
    {
        $moved = $this->fromSapi ? move_uploaded_file($this->tempPath, $targetPath) : rename($this->tempPath, $targetPath);
        if (! $moved) {
            throw new RuntimeException('Unable to move the uploaded file.');
        }
        @chmod($targetPath, 0644);
    }

    /**
     * Normalize $_FILES (including nested "field[]" uploads) into field => file|list<file>.
     *
     * @param array<string, mixed> $files
     * @return array<string, UploadedFileInterface|list<UploadedFileInterface>>
     */
    public static function fromGlobals(array $files): array
    {
        $result = [];
        foreach ($files as $field => $spec) {
            if (! is_array($spec) || ! isset($spec['tmp_name'])) {
                continue;
            }
            if (is_array($spec['tmp_name'])) {
                $list = [];
                foreach (array_keys($spec['tmp_name']) as $i) {
                    if (is_array($spec['tmp_name'][$i])) {
                        continue; // deeper nesting is not supported
                    }
                    $list[] = new self($spec['tmp_name'][$i], (string) $spec['name'][$i], (string) $spec['type'][$i], (int) $spec['size'][$i], (int) $spec['error'][$i]);
                }
                $result[(string) $field] = $list;
            } else {
                $result[(string) $field] = new self($spec['tmp_name'], (string) $spec['name'], (string) $spec['type'], (int) $spec['size'], (int) $spec['error']);
            }
        }

        return $result;
    }
}
