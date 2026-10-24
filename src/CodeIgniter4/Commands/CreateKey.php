<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * php spark grocery-restable:key <name> [role]
 */
class CreateKey extends BaseCommand
{
    protected $group = 'Grocery Restable';
    protected $name = 'grocery-restable:key';
    protected $description = 'Creates an API key (only its hash is stored; the key is printed once).';
    protected $usage = 'grocery-restable:key <name> [role]';
    protected $arguments = ['name' => 'Descriptive name', 'role' => 'Role (default: authenticated)'];

    public function run(array $params)
    {
        /** @var \GroceryRestable\GroceryRestable $api */
        $api = service('groceryRestable');
        try {
            $key = $api->store()->createApiKey((string) ($params[0] ?? 'CLI key'), (string) ($params[1] ?? 'authenticated'));
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }
        CLI::write($key, 'yellow');

        return EXIT_SUCCESS;
    }
}
