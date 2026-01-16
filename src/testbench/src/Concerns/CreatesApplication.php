<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

/**
 * Provides hooks for registering package service providers and aliases.
 */
trait CreatesApplication
{
    /**
     * Get package providers.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /**
     * Get package aliases.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [];
    }

    /**
     * Register package providers.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     */
    protected function registerPackageProviders($app): void
    {
        foreach ($this->getPackageProviders($app) as $provider) {
            $app->register($provider);
        }
    }

    /**
     * Register package aliases.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     */
    protected function registerPackageAliases($app): void
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
