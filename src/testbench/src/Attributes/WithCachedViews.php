<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\AfterEach;
use Hypervel\Testbench\Contracts\Attributes\BeforeEach;

use function Hypervel\Testbench\artisan;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class WithCachedViews implements AfterEach, BeforeEach
{
    /**
     * Handle the attribute.
     */
    public function beforeEach(ApplicationContract $app): void
    {
        // BeforeEach attributes run after the parallel setUpTestCase callbacks, which
        // have already selected the worker's own compiled view path.
        artisan($app, 'view:cache');

        if ($app->bound('view')) {
            $app->make('view')->flushFinderCache();
        }
    }

    /**
     * Handle the attribute.
     */
    public function afterEach(ApplicationContract $app): void
    {
        artisan($app, 'view:clear');
    }
}
