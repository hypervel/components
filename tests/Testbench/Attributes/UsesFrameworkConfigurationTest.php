<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Testbench\Attributes\UsesFrameworkConfiguration;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UsesFrameworkConfigurationTest extends TestCase
{
    protected bool $loadEnvironmentVariables = false;

    #[Test]
    public function itCanLoadUsingTestbenchConfigurations(): void
    {
        $this->assertSame(\Hypervel\Testbench\Bootstrap\LoadConfiguration::class, $this->app[LoadConfiguration::class]::class);

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';

        $this->assertSame($environment, config('app.env'));
        $this->assertSame(\Hypervel\Foundation\Auth\User::class, config('auth.providers.users.model'));
    }

    #[Test]
    #[UsesFrameworkConfiguration]
    public function itCanLoadUsingFrameworkConfigurations(): void
    {
        $this->assertSame(LoadConfiguration::class, $this->app[LoadConfiguration::class]::class);

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'production';

        $this->assertSame($environment, config('app.env'));
        $this->assertSame(\App\Models\User::class, config('auth.providers.users.model'));
    }
}
