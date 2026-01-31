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
     */
    protected function registerPackageProviders(ApplicationContract $app): void
    {
        foreach ($this->getPackageProviders($app) as $provider) {
            $app->register($provider);
        }
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
