<?php

declare(strict_types=1);

namespace GroceryRestable\Http;

use GroceryRestable\Admin\NativeSession;
use GroceryRestable\GroceryRestable;
use GroceryRestable\Support\Size;

/**
 * Serve Grocery Restable from a plain PHP front controller (no framework):
 *
 *   // public/index.php
 *   $api = GroceryRestable::create(require __DIR__ . '/../config/grocery-restable.php');
 *   if (! NativeServer::serve($api)) {
 *       http_response_code(404);
 *   }
 */
final class NativeServer
{
    /**
     * Dispatch the current request to the API or the admin GUI based on the configured URLs.
     * Returns false when the request belongs to neither.
     */
    public static function serve(GroceryRestable $api): bool
    {
        $path = self::currentPath();
        $apiPath = self::mountPath($api->config()->apiUrl());
        $adminPath = self::mountPath($api->config()->adminUrl());

        if ($api->config()->get('adminEnabled', true) && self::isUnder($path, $adminPath)) {
            $api->handleAdmin(self::requestFromGlobals(substr($path, strlen($adminPath)), $api->config()->adminUrl()), new NativeSession())->send();

            return true;
        }
        if (self::isUnder($path, $apiPath)) {
            // php.ini post_max_size does not apply to PUT / PATCH bodies, which are read into memory.
            $maxBody = Size::toBytes((string) $api->config()->get('maxBodySize', '10MB'));
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
                Response::json(['error' => ['code' => 'payload_too_large', 'message' => 'The request body is too large.']], 413)->send();

                return true;
            }
            $api->handle(self::requestFromGlobals(substr($path, strlen($apiPath)), $api->config()->apiUrl()))->send();

            return true;
        }

        return false;
    }

    public static function requestFromGlobals(string $path, string $baseUrl): Request
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[str_replace('_', '-', strtolower($key))] = (string) $value;
            }
        }
        if (! isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $contentType = strtolower((string) ($headers['content-type'] ?? ''));
        // php://input is not available for multipart/form-data requests.
        $raw = str_starts_with($contentType, 'multipart/form-data') ? '' : (string) file_get_contents('php://input');

        return new Request(
            $method,
            $path,
            (string) ($_SERVER['QUERY_STRING'] ?? ''),
            $headers,
            $raw,
            $method === 'POST' ? $_POST : [],
            NativeUploadedFile::fromGlobals($_FILES),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            self::absolute($baseUrl),
        );
    }

    private static function currentPath(): string
    {
        // Not decoded here: Request::segments() decodes each segment once (no "%252F" double decoding).
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        // Support URLs such as /index.php/api/...
        if ($script !== '' && str_starts_with($path, $script . '/')) {
            $path = substr($path, strlen($script));
        }

        return '/' . ltrim($path, '/');
    }

    private static function mountPath(string $url): string
    {
        return '/' . trim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    private static function isUnder(string $path, string $mount): bool
    {
        return $mount !== '/' && ($path === $mount || str_starts_with($path, $mount . '/'));
    }

    private static function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . ltrim($url, '/');
    }
}
