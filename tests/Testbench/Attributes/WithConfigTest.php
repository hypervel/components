<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WithConfigTest extends TestCase
{
    /**
     * Get package providers.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [WithConfigTestServiceProvider::class];
    }

    #[Test]
    #[WithConfig('testbench.attribute', true)]
    public function itCanResolveDefinedConfiguration(): void
    {
        $this->assertTrue(config('testbench.attribute'));
    }

    // REMOVED: Orchestra's deferred WithConfig test conflicts with Hypervel's
    // process-global worker-startup configuration model.

    #[Test]
    #[WithConfig('testbench.session.attribute', true)]
    public function itCanEagerlyResolveDefinedConfiguration(): void
    {
        $this->assertTrue(config('testbench.session.attribute'));
        $this->assertNull(config('testbench.session.report'));
        $this->assertSame(1, config('testbench.api'));
    }

    #[Test]
    public function itDoesNotPersistDefinedConfigurationBetweenTests(): void
    {
        $this->assertNull(config('testbench.attribute'));
    }

    #[Test]
    #[WithConfig('testbench.lifecycle_value', 'configured')]
    public function itAppliesConfigurationBeforeProvidersRegisterAndBoot(): void
    {
        $this->assertSame(
            'configured',
            config('testbench.provider_observed_during_register'),
        );
        $this->assertSame(
            'configured',
            config('testbench.provider_observed_during_boot'),
        );
    }
}

class WithConfigTestServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $config = $this->app->make('config');
        $config->set(
            'testbench.provider_observed_during_register',
            $config->get('testbench.lifecycle_value'),
        );
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Fixtures/with-config/testbench.php', 'testbench');

        $config = $this->app->make('config');
        $config->set(
            'testbench.provider_observed_during_boot',
            $config->get('testbench.lifecycle_value'),
        );
    }
}
