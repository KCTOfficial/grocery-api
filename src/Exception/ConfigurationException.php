<?php

declare(strict_types=1);

namespace GroceryRestable\Exception;

use LogicException;

/**
 * Developer / configuration mistakes (invalid resource definitions, missing paths, ...).
 * These are never shown verbatim to API clients unless debug mode is enabled.
 */
class ConfigurationException extends LogicException
{
}
