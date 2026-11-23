<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\TypedConfigGetters;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\Facades\Config;

class Settings
{
    public function reads(Repository $config, bool $secondary): void
    {
        config('typed.name');
        config('typed.port');
        Config::get('typed.ratio');
        app('config')->get('typed.debug');
        config('typed.hosts', []);
        config($secondary ? 'typed.name' : 'typed.label');
        config('typed.either');
        config('typed.nullable');
        config('typed.missing', 'fallback');
        config()->string('typed.name');
        Config::string('typed.name');
        config(['typed.name' => 'Other']);

        // Only the application's repository has the application's values
        $config->get('typed.name');
        (new ConfigRepository(['typed' => ['name' => 123]]))->get('typed.name');
    }
}
