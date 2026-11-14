<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedCallsOnly;

use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Facades\Config;

class Registry
{
    #[BootOnly]
    public static function register(): void
    {
    }
}

class SettingsController
{
    public function update(): void
    {
        $this->apply();
    }

    private function apply(): void
    {
        Registry::register();
        Config::set('app.name', 'Hypervel');
        Config::macro('settings', static fn (): string => '');
    }
}
