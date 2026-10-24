<?php

declare(strict_types=1);

namespace GroceryRestable\Admin;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Auth\Authenticator;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Database\ConnectionFactory;
use GroceryRestable\Database\ConnectionManager;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ValidationException;
use GroceryRestable\GroceryRestable;
use Throwable;

/**
 * First-time setup shared by the web wizard and the CLI commands.
 */
final class Installer
{
    public function __construct(private readonly GroceryRestable $api)
    {
    }

    /**
     * @param array<string, mixed> $database ['mode' => 'framework', 'connection' => 'default'] or a connection definition
     * @return array{ok: bool, message: string}
     */
    public function testDatabase(array $database): array
    {
        if (($database['mode'] ?? '') === 'framework') {
            $name = (string) ($database['connection'] ?? 'default');
            try {
                $this->api->connections()->get($name)->select('SELECT 1');

                return ['ok' => true, 'message' => 'Connected using the framework connection "' . $name . '".'];
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        }
        $error = ConnectionManager::test(self::connectionConfig($database));

        return ['ok' => $error === null, 'message' => $error ?? 'Connection successful.'];
    }

    /**
     * Complete the setup atomically and return the generated anon / service_role keys.
     *
     * @param array<string, mixed> $database
     * @param array{username?: string, password?: string, password_confirm?: string} $account
     * @param array<string, mixed> $options auth_mode, auto_expose, app_name
     * @return array{anon: string, service_role: string}
     */
    public function install(string $framework, array $database, array $account, array $options): array
    {
        $store = $this->api->store();
        if ($store->isSetupCompleted()) {
            throw ApiException::badRequest('Setup has already been completed.');
        }
        $choices = $this->api->config()->frameworkChoices();
        if (! isset($choices[$framework])) {
            throw ValidationException::forField('framework', 'Choose "No framework" or "CodeIgniter 4".');
        }
        if (! $choices[$framework]['available']) {
            throw ValidationException::forField('framework', $choices[$framework]['label'] . ' was not detected: install Grocery Restable inside a CodeIgniter 4 application to use it.');
        }
        if (array_key_exists('password_confirm', $account) && ($account['password'] ?? '') !== $account['password_confirm']) {
            throw ValidationException::forField('password_confirm', 'The passwords do not match.');
        }

        $connectionConfig = null;
        if (($database['mode'] ?? '') === 'framework') {
            if ($framework === 'standalone') {
                throw ValidationException::forField('database', 'Without a framework, configure the database connection here.');
            }
            $connectionName = (string) ($database['connection'] ?? 'default');
            if (! $this->api->connections()->isFrameworkConnection($connectionName)) {
                throw ValidationException::forField('database', 'Unknown framework connection "' . $connectionName . '".');
            }
            $test = $this->testDatabase($database);
            if (! $test['ok']) {
                throw ValidationException::forField('database', $test['message']);
            }
        } else {
            $connectionConfig = self::connectionConfig($database);
            $error = ConnectionManager::test($connectionConfig);
            if ($error !== null) {
                throw ValidationException::forField('database', $error);
            }
            $connectionName = $this->api->connections()->isFrameworkConnection('default') ? 'main' : 'default';
        }

        $authMode = in_array($options['auth_mode'] ?? '', Authenticator::MODES, true) ? (string) $options['auth_mode'] : 'api_key';
        $settings = [
            'framework' => $framework,
            'default_connection' => $connectionName,
            'auth_mode' => $authMode,
            'auto_expose' => (bool) ($options['auto_expose'] ?? false),
            'app_name' => trim((string) ($options['app_name'] ?? '')) ?: 'Grocery Restable',
            'setup_completed' => true,
        ];
        if (in_array($authMode, ['jwt', 'any'], true)) {
            $settings['jwt_secret'] = bin2hex(random_bytes(32));
        }

        // Everything is written atomically so a failed attempt can simply be retried.
        $pdo = $store->pdo();
        $pdo->beginTransaction();
        try {
            if ($connectionConfig !== null) {
                $store->saveConnection($connectionName, $connectionConfig);
            }
            $store->createUser((string) ($account['username'] ?? ''), (string) ($account['password'] ?? ''));
            // Like Supabase: a public "anon" key and a full access "service_role" key.
            $keys = [
                'anon' => $store->createApiKey('Anonymous (public) key', AuthContext::ANON),
                'service_role' => $store->createApiKey('Service role key', AuthContext::SERVICE_ROLE),
            ];
            $store->saveSettings($settings);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $keys;
    }

    /**
     * Normalize and validate a connection definition coming from a form.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function connectionConfig(array $input): array
    {
        $driver = (string) ($input['driver'] ?? '');
        $engine = ConnectionFactory::ENGINES[$driver] ?? null;
        if ($engine === null || ! $engine['supported']) {
            throw ValidationException::forField('driver', 'Choose a supported database engine.');
        }
        $client = (string) ($input['client'] ?? '') ?: 'pdo';
        try {
            ConnectionFactory::client(['driver' => $driver, 'client' => $client]);
        } catch (ConfigurationException $e) {
            throw ValidationException::forField('client', $e->getMessage());
        }
        $config = ['driver' => $driver, 'client' => $client, 'database' => trim((string) ($input['database'] ?? ''))];
        if ($driver !== 'sqlite') {
            $config += [
                'host' => trim((string) ($input['host'] ?? '127.0.0.1')) ?: '127.0.0.1',
                'port' => (int) ($input['port'] ?? 0) ?: ($driver === 'mysql' ? 3306 : 5432),
                'username' => (string) ($input['username'] ?? ''),
                'password' => (string) ($input['password'] ?? ''),
            ];
            if ($driver === 'mysql') {
                $config['charset'] = preg_match('/^[a-z0-9_]+$/i', (string) ($input['charset'] ?? '')) ? (string) $input['charset'] : 'utf8mb4';
            } else {
                $config['schema'] = trim((string) ($input['schema'] ?? 'public')) ?: 'public';
                $config['sslmode'] = in_array($input['sslmode'] ?? '', ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true) ? $input['sslmode'] : 'prefer';
            }
        }
        if ($config['database'] === '') {
            throw ValidationException::forField('database', 'The database name (or SQLite file path) is required.');
        }

        return $config;
    }
}
