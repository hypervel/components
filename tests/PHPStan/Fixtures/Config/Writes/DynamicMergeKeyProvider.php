<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Support\ServiceProvider;

use function dirname;

class DynamicMergeKeyProvider extends ServiceProvider
{
    /**
     * Merge a known package file under a key only known at runtime.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/package.php', $this->app->environment());
    }
}
