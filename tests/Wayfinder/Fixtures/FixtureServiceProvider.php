<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\URL;
use Hypervel\Support\ServiceProvider;

class FixtureServiceProvider extends ServiceProvider
{
    /**
     * Register Wayfinder test fixture configuration: the `export` filesystem
     * disk that `routes/storage.ts` is generated from, and a default value for
     * the `defaultDomain` URL parameter used by DomainController fixtures.
     */
    public function register(): void
    {
        Config::set([
            'filesystems.disks.export' => [
                'driver' => 'local',
                'root' => database_path('data/exports'),
                'serve' => true,
                'throw' => false,
            ],
        ]);

        URL::defaults([
            'defaultDomain' => 'tim.macdonald',
        ]);
    }
}
