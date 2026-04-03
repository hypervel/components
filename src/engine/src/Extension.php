<?php

declare(strict_types=1);

namespace Hypervel\Engine;

class Extension
{
    /**
     * Determine if the Swoole extension is loaded.
     */
    public static function isLoaded(): bool
    {
        return extension_loaded('Swoole');
    }
}
