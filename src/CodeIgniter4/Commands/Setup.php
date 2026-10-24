<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use GroceryRestable\Admin\Installer;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ValidationException;
use Throwable;

/**
 * php spark grocery-restable:setup
 *
 * Non-interactive alternative to the web setup wizard (useful on servers where the
 * wizard is restricted to localhost).
 */
class Setup extends BaseCommand
{
    protected $group = 'Grocery Restable';
    protected $name = 'grocery-restable:setup';
    protected $description = 'Runs the Grocery Restable first-time setup using a CodeIgniter database group.';
    protected $usage = 'grocery-restable:setup [--connection default] [--username admin] [--password secret] [--auth api_key] [--auto-expose] [--name "My API"]';
    protected $options = [
        '--connection' => 'Connection name from $databaseGroups (default: "default")',
        '--username' => 'Admin username (prompted when missing)',
        '--password' => 'Admin password (prompted when missing; avoid it in shared shells)',
        '--auth' => 'api_key | any | jwt | none (default: api_key)',
        '--auto-expose' => 'Expose every table automatically',
        '--name' => 'Application name',
    ];

    public function run(array $params)
    {
        /** @var \GroceryRestable\GroceryRestable $api */
        $api = service('groceryRestable');
        if ($api->store()->isSetupCompleted()) {
            CLI::error('Setup has already been completed.');

            return EXIT_ERROR;
        }

        $username = (string) (CLI::getOption('username') ?: CLI::prompt('Admin username', 'admin'));
        $password = (string) (CLI::getOption('password') ?: CLI::prompt('Admin password (min. 8 characters)', null, 'required|min_length[8]'));

        try {
            $keys = (new Installer($api))->install(
                'codeigniter4',
                ['mode' => 'framework', 'connection' => (string) (CLI::getOption('connection') ?: 'default')],
                ['username' => $username, 'password' => $password],
                [
                    'auth_mode' => (string) (CLI::getOption('auth') ?: 'api_key'),
                    'auto_expose' => CLI::getOption('auto-expose') !== null,
                    'app_name' => (string) (CLI::getOption('name') ?: 'Grocery Restable'),
                ],
            );
        } catch (ValidationException $e) {
            foreach ($e->getErrors() as $field => $error) {
                CLI::error($field . ': ' . $error);
            }

            return EXIT_ERROR;
        } catch (ApiException|Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Grocery Restable is ready.', 'green');
        CLI::newLine();
        CLI::write('anon key (public):          ' . $keys['anon'], 'yellow');
        CLI::write('service_role key (secret):  ' . $keys['service_role'], 'yellow');
        CLI::newLine();
        CLI::write('Store the keys now, they are not shown again. Admin GUI: ' . $api->config()->adminUrl());

        return EXIT_SUCCESS;
    }
}
