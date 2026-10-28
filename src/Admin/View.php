<?php

declare(strict_types=1);

namespace GroceryRestable\Admin;

/**
 * Renders the plain PHP templates of the admin GUI.
 */
final class View
{
    /**
     * CDN assets (latest versions at release time) with Subresource Integrity hashes.
     * Identical copies live in assets/vendor and are loaded by assets/loader.js when the CDN
     * is unreachable (offline use).
     */
    public const CDN = [
        'bootstrap_css' => [
            'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css',
            'sri' => 'sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB',
        ],
        'bootstrap_js' => [
            'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js',
            'sri' => 'sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI',
        ],
        'jquery' => [
            'url' => 'https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js',
            'sri' => 'sha384-fgGyf7Mo7DURSOMnOy7ed+dkq5Job205Gnzu6QIg0BOHKaqt4D76Dt8VlDCzcMHV',
        ],
        'fontawesome' => [
            'url' => 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css',
            'sri' => 'sha384-qrALq7+6jBOZIQsNnT6xGkMDru64qD6uTlDra39xrt2SoXl4pO3FX6Roz/RpR/BS',
        ],
        'highlightjs' => [
            'url' => 'https://cdn.jsdelivr.net/npm/@highlightjs/cdn-assets@11.12.0/highlight.min.js',
            'sri' => 'sha384-wjfDDhOPPdjtva8vWBhWeVprSpmxisEu5aYT3q1JyACqXpdKpo3PWZTMVq24MBix',
        ],
        'highlightjs_css' => [
            'url' => 'https://cdn.jsdelivr.net/npm/@highlightjs/cdn-assets@11.12.0/styles/github-dark.min.css',
            'sri' => 'sha384-wH75j6z1lH97ZOpMOInqhgKzFkAInZPPSPlZpYKYTOqsaizPvhQZmAtLcPKXpLyH',
        ],
    ];

    public function __construct(private readonly string $directory = __DIR__ . '/views')
    {
    }

    /**
     * Cache busting token for admin.js / admin.css / loader.js and the modules in assets/app
     * (changes when one of them changes).
     */
    public static function assetVersion(): string
    {
        $files = ['admin.js', 'admin.css', 'loader.js'];
        foreach (['app/*.js', 'app/pages/*.js'] as $pattern) {
            foreach (glob(__DIR__ . '/assets/' . $pattern) ?: [] as $path) {
                $files[] = substr($path, strlen(__DIR__ . '/assets/'));
            }
        }
        $stamp = '';
        foreach ($files as $file) {
            $stamp .= (string) @filemtime(__DIR__ . '/assets/' . $file);
        }

        return substr(md5(\GroceryRestable\GroceryRestable::VERSION . $stamp), 0, 10);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . $template . '.php';
        if (! preg_match('/^[a-z_-]+$/', $template) || ! is_file($file)) {
            throw new \InvalidArgumentException('Unknown admin template ' . $template);
        }

        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cdn = self::CDN;
        $assetVersion = self::assetVersion();
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            include $file;

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
