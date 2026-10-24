<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Http;

use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use GroceryRestable\Http\Request;
use GroceryRestable\Http\Response;

/**
 * Converts CodeIgniter requests / responses to and from Grocery Restable ones.
 */
final class Bridge
{
    /**
     * @param list<string> $segments URI segments after the mount point (controller method arguments).
     */
    public static function request(IncomingRequest $request, array $segments, string $baseUrl): Request
    {
        $headers = [];
        foreach ($request->headers() as $name => $header) {
            $headers[$name] = is_array($header)
                ? implode(', ', array_map(static fn ($h): string => $h->getValueLine(), $header))
                : $header->getValueLine();
        }

        $files = [];
        foreach ($request->getFiles() as $field => $file) {
            if ($file instanceof UploadedFile) {
                $files[(string) $field] = new CodeIgniterUploadedFile($file);
            } elseif (is_array($file)) {
                $files[(string) $field] = array_values(array_map(
                    static fn (UploadedFile $f): CodeIgniterUploadedFile => new CodeIgniterUploadedFile($f),
                    array_filter($file, static fn ($f): bool => $f instanceof UploadedFile),
                ));
            }
        }

        $form = $request->getMethod() === 'POST' ? (array) $request->getPost() : [];

        return new Request(
            $request->getMethod(),
            implode('/', array_map('rawurlencode', $segments)),
            (string) ($request->getServer('QUERY_STRING') ?? $request->getUri()->getQuery()),
            $headers,
            (string) $request->getBody(),
            $form,
            $files,
            $request->getIPAddress(),
            $baseUrl,
        );
    }

    public static function response(Response $response, ResponseInterface $ciResponse): ResponseInterface
    {
        if ($response->getFilePath() !== null) {
            $download = new DownloadResponse((string) $response->getFileName(), true);
            $download->setFilePath($response->getFilePath());
            $download->setFileName((string) $response->getFileName());
            if ($response->isInline()) {
                $download->inline();
            }
            foreach ($response->getHeaders() as $name => $value) {
                if (! in_array(strtolower($name), ['content-disposition', 'content-length'], true)) {
                    $download->setHeader($name, $value);
                }
            }

            return $download;
        }

        $status = $response->getStatus();
        $ciResponse->setStatusCode($status, $status === 419 ? 'Page Expired' : '');
        foreach ($response->getHeaders() as $name => $value) {
            $ciResponse->setHeader($name, $value);
        }

        return $ciResponse->setBody($response->getBody());
    }
}
