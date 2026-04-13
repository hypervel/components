<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures\Providers;

use Hypervel\Support\ServiceProvider;

class CustomConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = [
            'foo' => 'bar',
        ];

        foreach ($config as $name => $params) {
            config(['database.redis.' . $name => $params]);
        }
    }
}
