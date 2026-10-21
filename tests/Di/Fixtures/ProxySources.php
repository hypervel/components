<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Fixtures;

use Hypervel\Di\Bootstrap\GenerateProxies;

class ProxySources extends GenerateProxies
{
    /**
     * Run the callback while the given proxy files map to the given source files.
     *
     * @param array<string, string> $sources canonical proxy path => canonical source path
     */
    public static function with(array $sources, callable $callback): mixed
    {
        // Use the inherited map so the production consumers see these entries.
        $previous = static::$proxySources;
        static::$proxySources = $sources + $previous;

        try {
            return $callback();
        } finally {
            static::$proxySources = $previous;
        }
    }
}
