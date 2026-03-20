<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\ParallelTesting;

trait TestViews
{
    /**
     * The original compiled view path prior to appending the token.
     */
    protected static ?string $originalCompiledViewPath = null;

    /**
     * Boot test views for parallel testing.
     */
    protected function bootTestViews(): void
    {
        ParallelTesting::setUpProcess(function () {
            if ($path = $this->parallelSafeCompiledViewPath()) {
                File::ensureDirectoryExists($path);
            }
        });

        ParallelTesting::setUpTestCase(function () {
            if ($path = $this->parallelSafeCompiledViewPath()) {
                $this->switchToCompiledViewPath($path);
            }
        });

        ParallelTesting::tearDownProcess(function () {
            if ($path = $this->parallelSafeCompiledViewPath()) {
                File::deleteDirectory($path);
            }
        });
    }

    /**
     * Get the test compiled view path.
     */
    protected function parallelSafeCompiledViewPath(): ?string
    {
        self::$originalCompiledViewPath ??= $this->app['config']->get('view.compiled', '');

        if (! self::$originalCompiledViewPath) {
            return null;
        }

        return rtrim(self::$originalCompiledViewPath, '\/')
            . '/test_'
            . ParallelTesting::token();
    }

    /**
     * Switch to the given compiled view path.
     */
    protected function switchToCompiledViewPath(string $path): void
    {
        $this->app['config']->set('view.compiled', $path);

        if ($this->app->resolved('blade.compiler')) {
            $compiler = $this->app['blade.compiler'];

            (function () use ($path) {
                $this->cachePath = $path; /* @phpstan-ignore property.notFound */
            })->bindTo($compiler, $compiler)();
        }
    }
}
