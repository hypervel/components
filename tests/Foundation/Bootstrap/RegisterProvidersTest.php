<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Support\Composer;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class RegisterProvidersTest extends TestCase
{
    use HasMockedApplication;

    public function tearDown(): void
    {
        Composer::setBasePath(null);

        parent::tearDown();
    }

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

        $app = $this->getApplication([
            ConfigContract::class => fn () => $config,
        ]);

        Composer::setBasePath(dirname(__DIR__) . '/fixtures/project1');

        (new RegisterProviders())->bootstrap($app);

        $this->assertSame('foo', $app->make('foo'));
        $this->assertSame('bar', $app->make('bar'));

        // should not register TestThreeServiceProvider because of `dont-discover`
        $this->assertFalse($app->bound('baz'));
        $this->assertFalse($app->bound('qux'));
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
