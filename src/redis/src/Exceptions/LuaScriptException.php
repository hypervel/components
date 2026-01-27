<?php

declare(strict_types=1);

namespace Hypervel\Redis\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a Lua script execution fails.
 */
class LuaScriptException extends RuntimeException
{
}
