<?php

/**
 * Standalone (framework-less) example.
 *
 *   composer install
 *   php -S 127.0.0.1:8080 examples/standalone/index.php
 *
 * Then open http://127.0.0.1:8080/grocery-restable to run the setup wizard.
 * The API is served at http://127.0.0.1:8080/api
 */

declare(strict_types=1);

use GroceryRestable\GroceryRestable;
use GroceryRestable\Http\NativeServer;

require __DIR__ . '/../../vendor/autoload.php';

$config = require __DIR__ . '/../../config/grocery-restable.php';
$config['storagePath'] = getenv('GROCERY_RESTABLE_STORAGE') ?: sys_get_temp_dir() . '/grocery-restable-standalone';
$config['debug'] = true;

$api = GroceryRestable::create($config);

// Resources can also be defined in code, exactly like Grocery CRUD:
// $api->resource('employees')
//     ->setSubject('Employee')
//     ->setRelation('officeCode', 'offices', 'city')
//     ->requiredFields('lastName');

if (! NativeServer::serve($api)) {
    if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/') {
        header('Location: /grocery-restable');
    } else {
        http_response_code(404);
    }
}
