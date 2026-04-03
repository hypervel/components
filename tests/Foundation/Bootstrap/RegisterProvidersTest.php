<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class RegisterProvidersTest extends TestCase
{
    public function testRegisterProviders()
    {
        $mergedProviders = null;
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('app.providers')
            ->andReturn([
                TestTwoServiceProvider::class,
            ]);
        $config->shouldReceive('set')
            ->with('app.providers', m::type('array'))
            ->once()
            ->andReturnUsing(function (string $key, array $value) use (&$mergedProviders) {
                $mergedProviders = $value;
            });
        $config->shouldReceive('get')
            ->with('app.providers', [])
            ->andReturnUsing(function () use (&$mergedProviders) {
                return $mergedProviders ?? [];
            });

        $manifest = m::mock(PackageManifest::class);
        $manifest->shouldReceive('providers')
            ->once()
            ->andReturn([
                TestOneServiceProvider::class,
            ]);

        $app = new Application($this->app->basePath());
        $app->singleton('config', fn () => $config);
        $app->singleton(PackageManifest::class, fn () => $manifest);

        (new RegisterProviders())->bootstrap($app);

        // TestOneServiceProvider discovered via PackageManifest, registers 'foo'
        $this->assertSame('foo', $app->make('foo'));

        // TestTwoServiceProvider from config app.providers, registers 'bar'
        $this->assertSame('bar', $app->make('bar'));
    }
}

class TestOneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('foo', function () {
            return 'foo';
        });
    }
}

class TestTwoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('bar', function () {
            return 'bar';
        });
    }
}

class TestThreeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('baz', function () {
            return 'baz';
        });
    }
}

class TestFourServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('qux', function () {
            return 'qux';
        });
    }
}
