<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * php spark grocery-restable:admin <username>
 */
class CreateAdmin extends BaseCommand
{
    protected $group = 'Grocery Restable';
    protected $name = 'grocery-restable:admin';
    protected $description = 'Creates an admin GUI user, or resets its password with --reset.';
    protected $usage = 'grocery-restable:admin <username> [--password secret] [--reset]';
    protected $arguments = ['username' => 'Admin username'];
    protected $options = ['--password' => 'Password (prompted when missing)', '--reset' => 'Change the password of an existing user'];

    public function run(array $params)
    {
        /** @var \GroceryRestable\GroceryRestable $api */
        $api = service('groceryRestable');
        $store = $api->store();
        $username = (string) ($params[0] ?? CLI::prompt('Username'));
        $password = (string) (CLI::getOption('password') ?: CLI::prompt('Password (min. 8 characters)', null, 'required|min_length[8]'));

        try {
            if (CLI::getOption('reset') !== null) {
                $user = null;
                foreach ($store->users() as $candidate) {
                    if (strcasecmp($candidate['username'], $username) === 0) {
                        $user = $candidate;
                    }
                }
                if ($user === null) {
                    CLI::error('Unknown user "' . $username . '".');

                    return EXIT_ERROR;
                }
                $store->changePassword((int) $user['id'], $password);
                CLI::write('Password changed.', 'green');
            } else {
                $store->createUser($username, $password);
                CLI::write('Admin user "' . $username . '" created.', 'green');
            }
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
