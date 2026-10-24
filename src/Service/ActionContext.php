<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Database\ConnectionInterface;
use GroceryRestable\Http\Request;
use GroceryRestable\Resource\ResolvedResource;

/**
 * Passed to custom actions and RPC functions.
 */
final class ActionContext
{
    public function __construct(
        public readonly AuthContext $auth,
        public readonly Request $request,
        public readonly ConnectionInterface $db,
        public readonly ?ResolvedResource $resource = null,
    ) {
    }
}
