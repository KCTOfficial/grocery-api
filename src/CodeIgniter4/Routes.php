<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4;

use CodeIgniter\Router\RouteCollection;
use GroceryRestable\CodeIgniter4\Config\GroceryRestable as GroceryRestableConfig;

/**
 * Route helpers.
 *
 *   // app/Config/Routes.php
 *   \GroceryRestable\CodeIgniter4\Routes::register($routes);                          // /api/* and /grocery-restable/*
 *   \GroceryRestable\CodeIgniter4\Routes::resource($routes, 'crud/employees', 'Employees::index');  // one controller method
 */
final class Routes
{
    public const VERBS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public static function register(RouteCollection $routes, ?GroceryRestableConfig $config = null): void
    {
        $config ??= Factory::config();

        $apiOptions = ['namespace' => 'GroceryRestable\CodeIgniter4\Controllers'];
        if ($config->apiFilters !== []) {
            $apiOptions['filter'] = $config->apiFilters;
        }
        self::resource($routes, trim($config->apiRoute, '/'), 'ApiController::handle', $apiOptions);

        if ($config->adminEnabled) {
            $adminOptions = ['namespace' => 'GroceryRestable\CodeIgniter4\Controllers'];
            if ($config->adminFilters !== []) {
                $adminOptions['filter'] = $config->adminFilters;
            }
            self::resource($routes, trim($config->adminRoute, '/'), 'AdminController::handle', $adminOptions);
        }
    }

    /**
     * Route "$path" and "$path/anything" (every HTTP verb) to $handler. The remaining
     * segments are passed as method arguments: public function index(string ...$segments)
     *
     * @param array<string, mixed> $options
     */
    public static function resource(RouteCollection $routes, string $path, string $handler, array $options = []): void
    {
        $path = trim($path, '/');
        $routes->match(self::VERBS, $path === '' ? '/' : $path, $handler, $options);
        $routes->match(self::VERBS, ($path === '' ? '' : $path . '/') . '(:any)', $handler . '/$1', $options);
    }
}
