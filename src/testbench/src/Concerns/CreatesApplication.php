<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;

/**
 * Provides hooks for registering package service providers and aliases.
 */
trait CreatesApplication
{
    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [];
    }

    /**
     * Get package aliases.
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases(ApplicationContract $app): array
    {
        return [];
    }

    /**
     * Register package providers.
     *
     * Merges the test's package providers into config('app.providers') so they
     * are registered by RegisterProviders during bootstrap, matching the
     * Orchestral Testbench pattern.
     */
    protected function registerPackageProviders(ApplicationContract $app): void
    {
        $packageProviders = $this->getPackageProviders($app);

        if (empty($packageProviders)) {
            return;
        }

        $config = $app->make('config');
        $existing = $config->get('app.providers', []);
        $config->set('app.providers', array_merge($existing, $packageProviders));
    }

    /**
     * Register package aliases.
     */
    protected function registerPackageAliases(ApplicationContract $app): void
    {
        $aliases = $this->getPackageAliases($app);

        if (empty($aliases)) {
            return;
        }

        $config = $app->get('config');
        $existing = $config->get('app.aliases', []);
        $config->set('app.aliases', array_merge($existing, $aliases));
    }
}
