<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config;

use Hypervel\Support\ServiceProvider;

class OverlappingConfigServiceProvider extends ServiceProvider
{
    /**
     * Merge a file under a key another provider also merges.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/overlap.php', 'overlap');
    }
}
