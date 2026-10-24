<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use GroceryRestable\Support\Crypto;

/**
 * php spark grocery-restable:publish
 */
class Publish extends BaseCommand
{
    protected $group = 'Grocery Restable';
    protected $name = 'grocery-restable:publish';
    protected $description = 'Creates app/Config/GroceryRestable.php so you can customize Grocery Restable and define resources in code.';
    protected $usage = 'grocery-restable:publish [--force]';
    protected $options = ['--force' => 'Overwrite an existing app/Config/GroceryRestable.php'];

    public function run(array $params)
    {
        $target = APPPATH . 'Config' . DIRECTORY_SEPARATOR . 'GroceryRestable.php';
        if (is_file($target) && ! CLI::getOption('force')) {
            CLI::error('app/Config/GroceryRestable.php already exists. Use --force to overwrite it.');

            return EXIT_ERROR;
        }

        $template = <<<'PHP'
            <?php

            namespace Config;

            use GroceryRestable\CodeIgniter4\Config\GroceryRestable as BaseGroceryRestable;

            /**
             * Grocery Restable configuration. See vendor/kctofficial/grocery-restable/docs/codeigniter4.md
             */
            class GroceryRestable extends BaseGroceryRestable
            {
                public string $apiRoute = 'api';

                public string $adminRoute = 'grocery-restable';

                public bool $autoRoutes = true;

                public bool $adminEnabled = true;

                /** @var list<string> */
                public array $apiFilters = [];

                /** @var list<string> */
                public array $adminFilters = [];

                /** @var array<string, string> connection name => app/Config/Database.php group */
                public array $databaseGroups = ['default' => 'default'];

                /**
                 * Define resources in code (they take precedence over the GUI).
                 */
                public function define(\GroceryRestable\GroceryRestable $api): void
                {
                    // $api->resource('employees')
                    //     ->setSubject('Employee')
                    //     ->setRelation('officeCode', 'offices', 'city')
                    //     ->requiredFields('lastName', 'email')
                    //     ->setRules('email', 'required|valid_email')
                    //     ->setFieldUpload('photo', 'employees', 'jpg|png');
                }
            }

            PHP;

        if (file_put_contents($target, $template) === false) {
            CLI::error('Unable to write ' . $target);

            return EXIT_ERROR;
        }

        CLI::write('Created app/Config/GroceryRestable.php', 'green');
        CLI::newLine();
        CLI::write('Recommended: add an encryption key for the secrets stored in SQLite to your .env:');
        CLI::write('  groceryrestable.encryptionKey = ' . Crypto::generateKey(), 'yellow');
        CLI::newLine();
        CLI::write('Then open ' . site_url('grocery-restable') . ' or run `php spark grocery-restable:setup`.');

        return EXIT_SUCCESS;
    }
}
