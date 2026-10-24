<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Config;

use CodeIgniter\Config\BaseService;
use GroceryRestable\CodeIgniter4\Factory;
use GroceryRestable\GroceryRestable;

/**
 * Discovered automatically by CodeIgniter: service('groceryRestable')
 */
class Services extends BaseService
{
    public static function groceryRestable(bool $getShared = true): GroceryRestable
    {
        if ($getShared) {
            return static::getSharedInstance('groceryRestable');
        }

        return Factory::create();
    }
}
