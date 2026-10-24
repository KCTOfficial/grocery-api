<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Http;

use CodeIgniter\HTTP\Files\UploadedFile;
use GroceryRestable\Http\UploadedFileInterface;

final class CodeIgniterUploadedFile implements UploadedFileInterface
{
    public function __construct(private readonly UploadedFile $file)
    {
    }

    public function getClientFilename(): string
    {
        return (string) $this->file->getClientName();
    }

    public function getClientMediaType(): string
    {
        return (string) $this->file->getClientMimeType();
    }

    public function getSize(): int
    {
        return (int) $this->file->getSize();
    }

    public function getError(): int
    {
        return $this->file->getError();
    }

    public function getTempPath(): string
    {
        return $this->file->getTempName();
    }

    public function moveTo(string $targetPath): void
    {
        $this->file->move(dirname($targetPath), basename($targetPath), true);
    }
}
