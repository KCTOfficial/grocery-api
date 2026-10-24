<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Libraries;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use GroceryRestable\CodeIgniter4\Http\Bridge;
use GroceryRestable\GroceryRestable as Engine;
use GroceryRestable\Resource\Resource;

/**
 * Grocery CRUD style usage: one controller method serves one table.
 *
 *   // app/Config/Routes.php
 *   \GroceryRestable\CodeIgniter4\Routes::resource($routes, 'crud/employees', 'Employees::index');
 *
 *   // app/Controllers/Employees.php
 *   public function index(string ...$segments)
 *   {
 *       $crud = new GroceryRestable();
 *       $crud->setTable('employees')
 *            ->setSubject('Employee')
 *            ->setRelation('officeCode', 'offices', 'city')
 *            ->requiredFields('lastName');
 *
 *       return $crud->render($segments);
 *   }
 *
 * Every Resource method is available, in camelCase or Grocery CRUD snake_case
 * (set_relation, unset_delete, callback_before_insert...).
 *
 * @mixin Resource
 */
class GroceryRestable
{
    private Resource $resource;

    public function __construct(?string $table = null, private ?Engine $engine = null)
    {
        $this->resource = new Resource('', $table);
    }

    /**
     * Handle the current request for this resource.
     *
     * @param list<string>|string $segments URI segments after the route of this controller method.
     */
    public function render(array|string $segments = []): ResponseInterface
    {
        $engine = $this->engine();
        $request = service('request');
        assert($request instanceof IncomingRequest);
        $segments = is_string($segments) ? array_values(array_filter(explode('/', $segments), 'strlen')) : array_values($segments);

        // The URL of this resource is the current URL minus the trailing segments.
        helper('url');
        $current = rtrim(current_url(), '/');
        $suffix = implode('/', array_map('rawurlencode', $segments));
        $baseUrl = $suffix !== '' && str_ends_with($current, '/' . $suffix) ? substr($current, 0, -strlen($suffix) - 1) : $current;

        $apiRequest = Bridge::request($request, $segments, $baseUrl);

        return Bridge::response($engine->render($this->resource, $apiRequest), service('response'));
    }

    public function getResource(): Resource
    {
        return $this->resource;
    }

    public function engine(): Engine
    {
        return $this->engine ??= service('groceryRestable');
    }

    /**
     * Forward fluent calls to the resource; chainable calls return this library.
     *
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->resource->{$method}(...$arguments);

        return $result === $this->resource ? $this : $result;
    }
}
