<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use GroceryRestable\CodeIgniter4\Http\Bridge;
use GroceryRestable\CodeIgniter4\Session\CodeIgniterSession;

/**
 * Serves the admin GUI: {adminRoute}/*
 */
class AdminController extends Controller
{
    public function handle(string ...$segments): ResponseInterface
    {
        /** @var \GroceryRestable\GroceryRestable $api */
        $api = service('groceryRestable');
        if (! $api->config()->get('adminEnabled', true)) {
            throw PageNotFoundException::forPageNotFound();
        }
        assert($this->request instanceof IncomingRequest);

        $request = Bridge::request($this->request, $segments, $api->config()->adminUrl());
        $response = $api->handleAdmin($request, new CodeIgniterSession(session()));

        return Bridge::response($response, $this->response);
    }
}
