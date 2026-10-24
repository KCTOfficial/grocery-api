<?php

declare(strict_types=1);

namespace GroceryRestable\Http;

/**
 * Framework independent HTTP response. Adapters convert it into their own response object;
 * standalone applications can call send().
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private ?string $fileName = null;
    private bool $inline = false;

    /**
     * @param array<string, string> $headers
     * @param string|null $filePath When set, the file content is the body (downloads).
     */
    public function __construct(
        private int $status = 200,
        array $headers = [],
        private string $body = '',
        private ?string $filePath = null,
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[self::normalizeName($name)] = $value;
        }
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, $json);
    }

    public static function noContent(int $status = 204, array $headers = []): self
    {
        return new self($status, $headers);
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers, $html);
    }

    public static function text(string $text, string $contentType = 'text/plain; charset=utf-8', int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => $contentType] + $headers, $text);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['Location' => $url]);
    }

    /**
     * Stream a file. Unknown / active content types are always sent as attachments.
     */
    public static function file(string $path, string $downloadName, string $mime, bool $inline = false): self
    {
        $safeInline = $inline && (bool) preg_match('#^(image/(png|jpe?g|gif|webp|bmp)|application/pdf|audio/|video/|text/plain)#', $mime);
        $name = str_replace(['"', "\r", "\n", '\\'], '', $downloadName);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'download';

        $response = new self(200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $safeInline ? 'inline' : 'attachment', $ascii, rawurlencode($name)),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], '', $path);
        $response->fileName = $name;
        $response->inline = $safeInline;

        return $response;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[self::normalizeName($name)] ?? null;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[self::normalizeName($name)] = $value;

        return $clone;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers[self::normalizeName($name)] = $value;
        }

        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    /**
     * Decode a JSON body (useful in tests and in the admin console).
     */
    public function decodedJson(): mixed
    {
        return $this->body === '' ? null : json_decode($this->body, true);
    }

    /**
     * Emit the response with PHP's native functions (standalone usage).
     */
    public function send(): void
    {
        if (! headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value), true);
            }
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);

            return;
        }
        echo $this->body;
    }

    private static function normalizeName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
