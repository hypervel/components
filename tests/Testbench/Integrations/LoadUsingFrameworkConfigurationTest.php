<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Testbench\Attributes\ResolvesHypervel;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\package_path;

/**
 * @internal
 * @coversNothing
 */
class LoadUsingFrameworkConfigurationTest extends TestCase
{
    #[Test]
    #[ResolvesHypervel('overrideHypervelConfiguration')]
    public function itCanLoadUsingFrameworkConfigurations(): void
    {
        $this->assertSame(LoadConfiguration::class, $this->app[LoadConfiguration::class]::class);

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'production';

        $this->assertSame($environment, config('app.env'));
        $this->assertSame(\App\Models\User::class, config('auth.providers.users.model'));
    }

    protected function overrideHypervelConfiguration(ApplicationContract $app): void
    {
        $app->instance(LoadConfiguration::class, new LoadConfiguration());
        $app->useConfigPath(package_path('src', 'foundation', 'config'));
    }
}
