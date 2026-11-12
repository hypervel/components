<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Support\ServiceProvider;

use function dirname;

class MergeCallableProvider extends ServiceProvider
{
    /**
     * Merge through a callable created from the merge method, and create a callable from a
     * helper that merges without calling it.
     */
    public function register(): void
    {
        $merge = $this->mergeConfigFrom(...);
        $merge(dirname(__DIR__) . '/config/package.php', 'package');

        $this->mergePartialConfig(...);
    }

    /**
     * Merge the partial configuration.
     */
    protected function mergePartialConfig(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/partial.php', 'partial');
    }
}
