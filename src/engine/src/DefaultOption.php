<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\DefaultOptionInterface;
use Hypervel\Engine\Exception\RuntimeException;

class DefaultOption implements DefaultOptionInterface
{
    /**
     * Get the coroutine hook flags.
     */
    public static function hookFlags(): int
    {
        if (! defined('SWOOLE_HOOK_ALL')) {
            throw new RuntimeException('The ext-swoole is required.');
        }

        return SWOOLE_HOOK_ALL;
    }
}
