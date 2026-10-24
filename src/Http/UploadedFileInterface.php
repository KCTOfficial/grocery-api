<?php

declare(strict_types=1);

namespace GroceryRestable\Http;

/**
 * Framework independent view of an uploaded file.
 */
interface UploadedFileInterface
{
    /** Original file name sent by the client. Never trust it for storage paths. */
    public function getClientFilename(): string;

    /** MIME type sent by the client. Never trust it; detect the real type from the content. */
    public function getClientMediaType(): string;

    public function getSize(): int;

    /** One of the UPLOAD_ERR_* constants. */
    public function getError(): int;

    /** Path of the temporary file, used to inspect the content before moving it. */
    public function getTempPath(): string;

    public function moveTo(string $targetPath): void;
}
