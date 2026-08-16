<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use App\Models\User as ApplicationUser;
use Hypervel\Foundation\Auth\User as FoundationUser;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Testbench\Attributes\UsesFrameworkConfiguration;
use Hypervel\Testbench\Bootstrap\LoadConfiguration as TestbenchLoadConfiguration;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UsesFrameworkConfigurationTest extends TestCase
{
    protected bool $loadEnvironmentVariables = false;

    #[Test]
    public function itCanLoadUsingTestbenchConfigurations(): void
    {
        $this->assertSame(TestbenchLoadConfiguration::class, $this->app->make(LoadConfiguration::class)::class);

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';

        $this->assertSame($environment, config('app.env'));
        $this->assertSame(FoundationUser::class, config('auth.providers.users.model'));
    }

    #[Test]
    #[UsesFrameworkConfiguration]
    public function itCanLoadUsingFrameworkConfigurations(): void
    {
        $this->assertSame(LoadConfiguration::class, $this->app->make(LoadConfiguration::class)::class);

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'production';

        $this->assertSame($environment, config('app.env'));
        $this->assertSame(ApplicationUser::class, config('auth.providers.users.model'));
    }
}
