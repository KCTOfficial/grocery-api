<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

use GroceryRestable\Config;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ValidationException;
use GroceryRestable\Http\UploadedFileInterface;
use GroceryRestable\Resource\UploadField;
use GroceryRestable\Store\ConfigStore;
use GroceryRestable\Support\Size;

/**
 * Validates and stores uploaded files.
 *
 * Security measures:
 *  - extension allow-list (per field / bucket or global setting),
 *  - server side executable extensions are always rejected,
 *  - active content (html, svg, js, xml) only when explicitly allowed,
 *  - the real MIME type (finfo) must match the extension,
 *  - random file name prefix, sanitized names, no path components,
 *  - files are stored outside the web root by default.
 */
final class UploadService
{
    public const ALWAYS_BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'inc',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'ascx', 'ashx', 'jsp', 'jspx',
        'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'so', 'scr', 'vbs', 'ps1',
        'htaccess', 'htpasswd', 'user.ini', 'ini', 'config',
    ];

    /** Extensions that are only accepted when explicitly listed for the field / bucket. */
    public const ACTIVE_CONTENT = ['html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'js', 'mjs', 'xml', 'xsl', 'swf'];

    private const DANGEROUS_MIME = [
        'text/html', 'application/xhtml+xml', 'text/x-php', 'application/x-php', 'application/x-httpd-php',
        'application/x-sh', 'text/x-shellscript', 'application/x-executable', 'application/x-dosexec',
        'application/x-msdownload', 'application/javascript', 'text/javascript', 'image/svg+xml',
        'application/x-mach-binary', 'application/x-elf',
    ];

    /** Expected MIME types (regex) for common extensions. */
    private const MIME_MAP = [
        'jpg' => '#^image/(p?jpeg)$#', 'jpeg' => '#^image/(p?jpeg)$#', 'png' => '#^image/png$#', 'gif' => '#^image/gif$#',
        'webp' => '#^image/webp$#', 'bmp' => '#^image/(x-ms-)?bmp$#', 'tif' => '#^image/tiff$#', 'tiff' => '#^image/tiff$#',
        'ico' => '#^image/(vnd\.microsoft\.icon|x-icon)$#', 'avif' => '#^image/avif$#', 'heic' => '#^image/hei[cf]$#',
        'pdf' => '#^application/pdf$#',
        'txt' => '#^(text/|application/(x-empty|octet-stream)$)#', 'csv' => '#^(text/|application/(csv|vnd\.ms-excel|x-empty)$)#',
        'md' => '#^(text/|application/x-empty$)#', 'log' => '#^(text/|application/x-empty$)#', 'json' => '#^(application/json|text/)#',
        'doc' => '#^application/(msword|vnd\.ms-office|octet-stream|CDFV2)#', 'xls' => '#^application/(vnd\.ms-excel|vnd\.ms-office|octet-stream|CDFV2)#',
        'ppt' => '#^application/(vnd\.ms-powerpoint|vnd\.ms-office|octet-stream|CDFV2)#', 'pps' => '#^application/(vnd\.ms-powerpoint|vnd\.ms-office|octet-stream)#',
        'docx' => '#^application/(vnd\.openxmlformats-officedocument|zip|octet-stream)#', 'xlsx' => '#^application/(vnd\.openxmlformats-officedocument|zip|octet-stream)#',
        'pptx' => '#^application/(vnd\.openxmlformats-officedocument|zip|octet-stream)#', 'ppsx' => '#^application/(vnd\.openxmlformats-officedocument|zip|octet-stream)#',
        'odt' => '#^application/(vnd\.oasis\.opendocument|zip)#', 'ods' => '#^application/(vnd\.oasis\.opendocument|zip)#', 'odp' => '#^application/(vnd\.oasis\.opendocument|zip)#',
        'zip' => '#^application/(zip|x-zip-compressed)$#', 'gz' => '#^application/(x-)?gzip$#', 'rar' => '#^application/(x-)?(rar|vnd\.rar)#', '7z' => '#^application/x-7z-compressed$#',
        'mp3' => '#^audio/(mpeg|mp3)$#', 'wav' => '#^audio/(x-)?wav$#', 'ogg' => '#^(audio|video|application)/ogg$#', 'm4a' => '#^(audio/(mp4|x-m4a)|video/mp4)$#',
        'mp4' => '#^video/mp4$#', 'm4v' => '#^video/(mp4|x-m4v)$#', 'mov' => '#^video/quicktime$#', 'webm' => '#^(video|audio)/webm$#',
        'avi' => '#^video/(x-msvideo|avi)$#', 'mpg' => '#^video/mpeg$#', 'wmv' => '#^video/x-ms-(wmv|asf)$#', 'flv' => '#^video/x-flv$#',
        '3gp' => '#^(video|audio)/3gpp$#', 'ogv' => '#^video/ogg$#',
        'svg' => '#^image/svg(\+xml)?$#',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ConfigStore $store,
    ) {
    }

    /**
     * Validate a file. Returns its extension and detected MIME type.
     *
     * @param list<string> $explicitTypes Extensions explicitly allowed for the field / bucket (empty = use global setting).
     * @return array{extension: string, mime: string}
     */
    public function validate(UploadedFileInterface $file, array $explicitTypes, ?string $maxSize, string $field = 'file'): array
    {
        $error = $file->getError();
        if ($error !== UPLOAD_ERR_OK) {
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw ApiException::payloadTooLarge('The uploaded file exceeds the server upload limit.');
            }
            throw ValidationException::forField($field, match ($error) {
                UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                default => 'The file could not be uploaded (error ' . $error . ').',
            });
        }

        $limit = Size::toBytes($maxSize ?? (string) $this->store->setting('upload_max_size', '20MB'));
        if ($limit !== null && $file->getSize() > $limit) {
            throw ApiException::payloadTooLarge(sprintf('The file exceeds the maximum size of %s.', $maxSize ?? $this->store->setting('upload_max_size')));
        }

        $name = $file->getClientFilename();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $explicitTypes !== [] ? $explicitTypes : self::parseTypes((string) $this->store->setting('upload_allowed_types', ''));

        if ($extension === '' || in_array($extension, self::ALWAYS_BLOCKED, true) || self::hasBlockedInnerExtension($name)) {
            throw ValidationException::forField($field, 'This file type is not allowed.');
        }
        if (! in_array($extension, $allowed, true) && ! in_array('*', $allowed, true)) {
            throw ValidationException::forField($field, 'Allowed file types: ' . implode(', ', $allowed) . '.');
        }
        if (in_array($extension, self::ACTIVE_CONTENT, true) && ! in_array($extension, $explicitTypes, true)) {
            throw ValidationException::forField($field, 'Active content (' . $extension . ') must be explicitly allowed for this field.');
        }

        $mime = $this->detectMime($file->getTempPath());
        $isActiveAllowed = in_array($extension, self::ACTIVE_CONTENT, true) && in_array($extension, $explicitTypes, true);
        if (! $isActiveAllowed && in_array($mime, self::DANGEROUS_MIME, true)) {
            throw ValidationException::forField($field, 'The file content is not allowed.');
        }
        if (isset(self::MIME_MAP[$extension]) && ! preg_match(self::MIME_MAP[$extension], $mime)) {
            throw ValidationException::forField($field, sprintf('The file content (%s) does not match its extension (.%s).', $mime, $extension));
        }

        return ['extension' => $extension, 'mime' => $mime];
    }

    /**
     * Store a field upload and return the information passed to callbacks.
     *
     * @return array{file_name: string, path: string, size: int, mime: string, original_name: string}
     */
    public function storeFieldFile(UploadField $field, UploadedFileInterface $file): array
    {
        $checked = $this->validate($file, $field->extensions(), $field->maxSize, $field->field);
        $directory = $this->fieldDirectory($field);
        $fileName = self::uniqueName($file->getClientFilename(), $checked['extension']);
        $target = $directory . DIRECTORY_SEPARATOR . $fileName;
        $size = $file->getSize();
        $file->moveTo($target);

        return ['file_name' => $fileName, 'path' => $target, 'size' => $size, 'mime' => $checked['mime'], 'original_name' => $file->getClientFilename()];
    }

    /**
     * Folder of a field upload; created unless $create is false.
     */
    public function fieldDirectory(UploadField $field, bool $create = true): string
    {
        $path = $field->path;
        $directory = self::isAbsolute($path) ? rtrim($path, '/\\') : $this->config->uploadPath() . ($path !== '' ? DIRECTORY_SEPARATOR . trim($path, '/\\') : '');
        if ($create && ! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create upload directory.');
        }

        return $directory;
    }

    /**
     * Absolute path of a stored field file, or null when the stored value is unsafe or missing.
     */
    public function fieldFilePath(UploadField $field, ?string $stored): ?string
    {
        if ($stored === null || $stored === '' || ! self::isSafeFileName($stored)) {
            return null;
        }
        $directory = realpath($this->fieldDirectory($field));
        $path = realpath($this->fieldDirectory($field) . DIRECTORY_SEPARATOR . $stored);
        if ($directory === false || $path === false || ! is_file($path) || ! str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    public function deleteFieldFile(UploadField $field, ?string $stored): void
    {
        $path = $this->fieldFilePath($field, $stored);
        if ($path !== null) {
            @unlink($path);
        }
    }

    public function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = @$finfo->file($path);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /**
     * @return list<string>
     */
    public static function parseTypes(string $types): array
    {
        return array_values(array_filter(array_map(
            static fn (string $t): string => strtolower(trim($t, " .\t")),
            preg_split('/[|,\s]+/', $types) ?: [],
        )));
    }

    /**
     * "Grocery CRUD style" name: random prefix + sanitized original name.
     */
    public static function uniqueName(string $originalName, string $extension): string
    {
        $base = self::sanitizeBaseName(pathinfo($originalName, PATHINFO_FILENAME));

        return bin2hex(random_bytes(4)) . '-' . $base . '.' . $extension;
    }

    public static function sanitizeBaseName(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
        $name = trim((string) preg_replace('/-+/', '-', $name), '-.');

        return $name === '' ? 'file' : strtolower(substr($name, 0, 80));
    }

    public static function isSafeFileName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,254}$/', $name) && ! str_contains($name, '..');
    }

    private static function hasBlockedInnerExtension(string $name): bool
    {
        // "shell.php.jpg" is rejected because some servers execute the inner extension.
        $parts = explode('.', strtolower($name));
        array_shift($parts);
        array_pop($parts);
        foreach ($parts as $part) {
            if (in_array($part, ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function isAbsolute(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path));
    }
}
