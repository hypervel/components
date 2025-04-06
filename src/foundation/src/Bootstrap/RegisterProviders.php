<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;
use Hypervel\Foundation\Providers\FoundationServiceProvider;
use Hypervel\Support\Composer;
use Throwable;

class RegisterProviders
{
    /**
     * Register App Providers.
     */
    public function bootstrap(ApplicationContract $app): void
    {
        $providers = [];
        $packagesToIgnore = $this->packagesToIgnore();

        if (! in_array('*', $packagesToIgnore)) {
            $providers = array_map(
                fn (array $package) => Arr::wrap(($package['hypervel']['providers'] ?? []) ?? []),
                Composer::getMergedExtra()
            );
            $providers = array_filter(
                $providers,
                fn ($package) => ! in_array($package, $packagesToIgnore),
                ARRAY_FILTER_USE_KEY
            );
            $providers = Arr::flatten($providers);
        }

        $providers = array_unique(
            array_merge(
                $providers,
                $app->get(ConfigInterface::class)->get('app.providers', [])
            )
        );

        // Ensure that FoundationServiceProvider is registered first.
        $foundationKey = array_search(FoundationServiceProvider::class, $providers);
        if ($foundationKey !== false) {
            unset($providers[$foundationKey]);
            array_unshift($providers, FoundationServiceProvider::class);
        }

        foreach ($providers as $providerClass) {
            $app->register($providerClass);
        }
    }

    protected function packagesToIgnore(): array
    {
        $packages = Composer::getMergedExtra('hypervel')['dont-discover'] ?? [];

        try {
            $project = Composer::getJsonContent()['extra']['hypervel']['dont-discover'] ?? [];
        } catch (Throwable) {
            $project = [];
        }

        return array_merge($packages, $project);
    }
}
