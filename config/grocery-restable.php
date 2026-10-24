<?php

/**
 * Grocery Restable bootstrap configuration for standalone PHP applications.
 * (CodeIgniter 4 uses app/Config/GroceryRestable.php instead — see docs/codeigniter4.md.)
 *
 * Copy this file into your project and pass it to GroceryRestable::create().
 * Everything else (connections, resources, keys, settings) is managed from the
 * admin GUI and stored in SQLite.
 */

return [
    'framework' => 'standalone',

    // Writable directory OUTSIDE the web root (SQLite store, key file, uploads, buckets).
    'storagePath' => __DIR__ . '/../writable/grocery-restable',

    // Public URLs (absolute or root relative) of the API and the admin GUI.
    'apiUrl' => '/api',
    'adminUrl' => '/grocery-restable',
    'adminEnabled' => true,

    // Never enable in production: error responses would contain exception details.
    'debug' => false,

    // Encrypts secrets stored in SQLite. Generate one with:
    //   php -r "echo 'base64:' . base64_encode(random_bytes(32));"
    'encryptionKey' => getenv('GROCERY_RESTABLE_KEY') ?: '',

    // Protects the web setup wizard when it is not opened from a trusted IP.
    'setupToken' => getenv('GROCERY_RESTABLE_SETUP_TOKEN') ?: '',
    'trustedSetupIps' => ['127.0.0.1', '::1'],

    // Optional: overrides the JWT secret stored in SQLite.
    'jwtSecret' => getenv('GROCERY_RESTABLE_JWT_SECRET') ?: '',

    'maxEmbedDepth' => 3,
    'maxBulkRows' => 1000,
];
