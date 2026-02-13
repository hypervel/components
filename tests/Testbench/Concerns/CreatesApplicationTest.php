<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CreatesApplicationTest extends TestCase
{
    protected array $registeredProviders = [];

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TestServiceProvider::class,
        ];
    }

    protected function getPackageAliases(ApplicationContract $app): array
    {
        return [
            'TestAlias' => TestFacade::class,
        ];
    }

    public function testGetPackageProvidersReturnsProviders(): void
    {
        $providers = $this->getPackageProviders($this->app);

        $this->assertContains(TestServiceProvider::class, $providers);
    }

    public function testGetPackageAliasesReturnsAliases(): void
    {
        $aliases = $this->getPackageAliases($this->app);

        $this->assertArrayHasKey('TestAlias', $aliases);
        $this->assertSame(TestFacade::class, $aliases['TestAlias']);
    }

    public function testRegisterPackageProvidersRegistersProviders(): void
    {
        // The provider should be registered via defineEnvironment
        // which calls registerPackageProviders
        $this->assertTrue(
            $this->app->providerIsLoaded(TestServiceProvider::class),
            'TestServiceProvider should be registered'
        );
    }

    public function testRegisterPackageAliasesAddsToConfig(): void
    {
        $aliases = $this->app->make('config')->get('app.aliases', []);

        $this->assertArrayHasKey('TestAlias', $aliases);
        $this->assertSame(TestFacade::class, $aliases['TestAlias']);
    }
}

/**
 * Test service provider for testing.
 */
class TestServiceProvider extends \Hypervel\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('test.service', fn () => 'test_value');
    }
}

/**
 * Test facade for testing.
 */
class TestFacade
{
    // Empty facade class for testing
}
