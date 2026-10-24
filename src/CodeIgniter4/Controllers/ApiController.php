<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use GroceryRestable\CodeIgniter4\Http\Bridge;

/**
 * Serves the whole REST API: {apiRoute}/*
 */
class ApiController extends Controller
{
    public function handle(string ...$segments): ResponseInterface
    {
        /** @var \GroceryRestable\GroceryRestable $api */
        $api = service('groceryRestable');
        assert($this->request instanceof IncomingRequest);

        $request = Bridge::request($this->request, $segments, $api->config()->apiUrl());

        return Bridge::response($api->handle($request), $this->response);
    }
}
