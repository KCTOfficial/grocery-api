<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4;

use Config\Database;
use GroceryRestable\CodeIgniter4\Config\GroceryRestable as GroceryRestableConfig;
use GroceryRestable\CodeIgniter4\Database\CodeIgniterConnection;
use GroceryRestable\CodeIgniter4\Validation\CodeIgniterValidator;
use GroceryRestable\GroceryRestable;

/**
 * Builds the Grocery Restable engine from the CodeIgniter configuration.
 */
final class Factory
{
    public static function create(?GroceryRestableConfig $config = null): GroceryRestable
    {
        $config ??= self::config();
        helper('url');

        $storage = $config->storagePath !== '' ? $config->storagePath : WRITEPATH . 'grocery-restable';
        $debug = $config->debug;
        if (is_string($debug)) {
            $debug = filter_var($debug, FILTER_VALIDATE_BOOLEAN);
        }

        $api = GroceryRestable::create([
            'framework' => 'codeigniter4',
            'storagePath' => $storage,
            'uploadPath' => $config->uploadPath !== '' ? $config->uploadPath : null,
            'bucketPath' => $config->bucketPath !== '' ? $config->bucketPath : null,
            'apiUrl' => rtrim(site_url(trim($config->apiRoute, '/')), '/'),
            'adminUrl' => rtrim(site_url(trim($config->adminRoute, '/')), '/'),
            'adminEnabled' => $config->adminEnabled,
            'debug' => $debug ?? (defined('ENVIRONMENT') && ENVIRONMENT === 'development'),
            'encryptionKey' => $config->encryptionKey,
            'setupToken' => $config->setupToken,
            'trustedSetupIps' => $config->trustedSetupIps,
            'jwtSecret' => $config->jwtSecret,
            'maxEmbedDepth' => $config->maxEmbedDepth,
            'maxBulkRows' => $config->maxBulkRows,
            'logger' => static function (string $level, string $message, array $context = []): void {
                unset($context['exception']);
                log_message($level, $message);
            },
        ]);

        foreach ($config->databaseGroups as $name => $group) {
            $settings = config(Database::class)->{$group} ?? null;
            if (! is_array($settings)) {
                continue;
            }
            $api->addConnection(
                (string) $name,
                static fn (): CodeIgniterConnection => new CodeIgniterConnection(Database::connect($group)),
                [
                    'driver' => CodeIgniterConnection::normalizeDriver((string) ($settings['DBDriver'] ?? '')),
                    'client' => 'CodeIgniter ' . ($settings['DBDriver'] ?? ''),
                    'label' => 'CodeIgniter group "' . $group . '"',
                    'host' => (string) ($settings['hostname'] ?? ''),
                    'database' => (string) ($settings['database'] ?? ''),
                ],
            );
        }

        if ($config->useCodeIgniterValidation) {
            $api->setValidator(new CodeIgniterValidator());
        }

        $api->booting(static fn (GroceryRestable $api) => $config->define($api));

        return $api;
    }

    public static function config(): GroceryRestableConfig
    {
        $config = config('GroceryRestable');

        return $config instanceof GroceryRestableConfig ? $config : new GroceryRestableConfig();
    }
}
