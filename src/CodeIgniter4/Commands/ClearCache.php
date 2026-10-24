<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark grocery-restable:cache
 */
class ClearCache extends BaseCommand
{
    protected $group = 'Grocery Restable';
    protected $name = 'grocery-restable:cache';
    protected $description = 'Clears the Grocery Restable schema cache (run it after migrations).';

    public function run(array $params)
    {
        /** @var \GroceryRestable\GroceryRestable $api */
        $api = service('groceryRestable');
        $api->store()->clearCache();
        CLI::write('Grocery Restable cache cleared.', 'green');

        return EXIT_SUCCESS;
    }
}
