<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Head\HeadServiceProvider;
use Hypervel\Inertia\InertiaServiceProvider;
use Hypervel\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    /**
     * Configure the shared view fixtures before providers boot.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('view.paths', [...$config->array('view.paths'), __DIR__ . '/Fixtures/views']);
    }

    /**
     * Get the providers used by the Head integration tests.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(Application $app): array
    {
        return [
            InertiaServiceProvider::class,
            // REMOVED: Livewire integration is not supported; Blade and Inertia cover page rendering.
            HeadServiceProvider::class,
        ];
    }
}
