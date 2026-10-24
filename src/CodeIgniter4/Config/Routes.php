<?php

declare(strict_types=1);

/**
 * Discovered automatically by CodeIgniter (Config\Modules::$aliases contains "routes").
 * Disable with $autoRoutes = false in app/Config/GroceryRestable.php.
 *
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use GroceryRestable\CodeIgniter4\Factory;
use GroceryRestable\CodeIgniter4\Routes;

$groceryRestableConfig = Factory::config();
if ($groceryRestableConfig->autoRoutes) {
    Routes::register($routes, $groceryRestableConfig);
}
unset($groceryRestableConfig);
