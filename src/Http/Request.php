<?php

declare(strict_types=1);

namespace GroceryRestable\Http;

use GroceryRestable\Exception\ApiException;
use GroceryRestable\Query\QueryParser;

/**
 * Immutable, framework independent HTTP request.
 *
 * Framework adapters build it from their own request objects. The path is relative
 * to the mount point of the handler (API base path or admin base path).
 */
final class Request
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, mixed>|list<mixed>|null */
    private array|null $parsedBody = null;
    private bool $bodyParsed = false;

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $formBody Parsed form fields ($_POST or equivalent).
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files
     */
    public function __construct(
        private readonly string $method,
        private string $path,
        private readonly string $queryString = '',
        array $headers = [],
        private readonly string $rawBody = '',
        private readonly array $formBody = [],
        private readonly array $files = [],
        private readonly string $clientIp = '',
        private readonly string $baseUrl = '',
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
    }

    /**
     * HTTP method, honoring X-HTTP-Method-Override / _method on POST requests
     * (multipart uploads can only be sent with POST by browsers and PHP).
     */
    public function method(): string
    {
        $method = strtoupper($this->method);
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->header('x-http-method-override') ?? $this->formBody['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public function path(): string
    {
        return trim($this->path, '/');
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        $path = $this->path();

        return $path === '' ? [] : array_map('rawurldecode', explode('/', $path));
    }

    public function queryString(): string
    {
        return $this->queryString;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function queryPairs(): array
    {
        return QueryParser::pairs($this->queryString);
    }

    public function queryParam(string $name): ?string
    {
        $value = null;
        foreach ($this->queryPairs() as [$key, $v]) {
            if ($key === $name) {
                $value = $v;
            }
        }

        return $value;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', (string) $this->header('content-type'))[0]));
    }

    public function isJson(): bool
    {
        $type = $this->contentType();

        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    public function isMultipart(): bool
    {
        return $this->contentType() === 'multipart/form-data';
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Parsed body: JSON (object or array of objects) or form fields.
     *
     * @return array<string, mixed>|list<mixed>
     */
    public function body(): array
    {
        if ($this->bodyParsed) {
            return $this->parsedBody ?? [];
        }
        $this->bodyParsed = true;

        if ($this->isJson()) {
            if (trim($this->rawBody) === '') {
                return $this->parsedBody = [];
            }
            try {
                $decoded = json_decode($this->rawBody, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                throw ApiException::badRequest('The request body is not valid JSON.');
            }
            if (! is_array($decoded)) {
                throw ApiException::badRequest('The JSON body must be an object or an array of objects.');
            }

            return $this->parsedBody = $decoded;
        }

        $form = $this->formBody;
        if ($form === [] && $this->rawBody !== '' && $this->contentType() === 'application/x-www-form-urlencoded') {
            parse_str($this->rawBody, $form);
        }
        unset($form['_method']);

        return $this->parsedBody = $form;
    }

    /**
     * Whether the body comes from a form (values are strings, "" means null for non-text columns).
     */
    public function isFormBody(): bool
    {
        return ! $this->isJson();
    }

    /**
     * @return array<string, UploadedFileInterface|list<UploadedFileInterface>>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->files[$name] ?? null;
        if (is_array($file)) {
            return $file[0] ?? null;
        }

        return $file;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    /**
     * Absolute or root-relative URL of the mount point (e.g. "https://example.com/api").
     */
    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function withPath(string $path): self
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }
}
