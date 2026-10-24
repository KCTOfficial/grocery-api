<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Grocery Restable configuration for CodeIgniter 4.
 *
 * Publish it with `php spark grocery-restable:publish` (creates app/Config/GroceryRestable.php)
 * or override single values in .env:
 *
 *   groceryrestable.encryptionKey = base64:...
 *   groceryrestable.jwtSecret = ...
 *
 * Only bootstrap values live here; everything else is managed in the admin GUI
 * and stored in SQLite.
 */
class GroceryRestable extends BaseConfig
{
    /**
     * URI of the REST API, relative to baseURL.
     */
    public string $apiRoute = 'api';

    /**
     * URI of the admin GUI, relative to baseURL.
     */
    public string $adminRoute = 'grocery-restable';

    /**
     * Register the API and admin routes automatically (module route discovery).
     * Set to false and call \GroceryRestable\CodeIgniter4\Routes::register($routes) yourself
     * to control where the routes are added.
     */
    public bool $autoRoutes = true;

    /**
     * Enable the admin GUI.
     */
    public bool $adminEnabled = true;

    /**
     * CodeIgniter filters applied to the API routes, e.g. ['throttle'] or ['cors'].
     *
     * @var list<string>
     */
    public array $apiFilters = [];

    /**
     * CodeIgniter filters applied to the admin routes (e.g. an IP allow-list filter).
     *
     * @var list<string>
     */
    public array $adminFilters = [];

    /**
     * Writable directory for the SQLite configuration store, key file, uploads and buckets.
     * Empty = WRITEPATH . 'grocery-restable'. Must NOT be inside the public directory.
     */
    public string $storagePath = '';

    /**
     * Base directory of field uploads. Empty = {storagePath}/uploads
     */
    public string $uploadPath = '';

    /**
     * Base directory of storage buckets. Empty = {storagePath}/buckets
     */
    public string $bucketPath = '';

    /**
     * CodeIgniter database groups exposed as Grocery Restable connections: connection name => group.
     * They appear as read-only "framework" connections in the GUI.
     *
     * @var array<string, string>
     */
    public array $databaseGroups = ['default' => 'default'];

    /**
     * Validate setRules() with the CodeIgniter validation service (all CI4 rules, including
     * your custom rules and is_unique). When false, the built-in validator is used.
     */
    public bool $useCodeIgniterValidation = true;

    /**
     * Key used to encrypt secrets stored in SQLite. "base64:..." / "hex:..." / 32+ chars.
     * Empty = a key file generated in the storage path.
     */
    public string $encryptionKey = '';

    /**
     * When set, the web setup wizard requires ?token=<setupToken>. Otherwise it is only
     * reachable from $trustedSetupIps. You can also run `php spark grocery-restable:setup`.
     */
    public string $setupToken = '';

    /**
     * @var list<string>
     */
    public array $trustedSetupIps = ['127.0.0.1', '::1'];

    /**
     * Overrides the JWT secret stored in SQLite.
     */
    public string $jwtSecret = '';

    /**
     * Return exception details in API errors. Null = enabled only in the development environment.
     */
    public ?bool $debug = null;

    public int $maxEmbedDepth = 3;

    public int $maxBulkRows = 1000;

    /**
     * Define resources, buckets and RPC functions in code (Grocery CRUD style).
     *
     *   public function define(\GroceryRestable\GroceryRestable $api): void
     *   {
     *       $api->resource('employees')
     *           ->setRelation('officeCode', 'offices', 'city')
     *           ->requiredFields('lastName');
     *   }
     */
    public function define(\GroceryRestable\GroceryRestable $api): void
    {
    }
}
