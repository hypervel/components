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
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('app.providers', [])
            ->once()
            ->andReturn([
                TestTwoServiceProvider::class,
            ]);

        $app = $this->getApplication([
            ConfigContract::class => fn () => $config,
        ]);

        Composer::setBasePath(dirname(__DIR__) . '/fixtures/project1');

        (new RegisterProviders())->bootstrap($app);

        $this->assertSame('foo', $app->get('foo'));
        $this->assertSame('bar', $app->get('bar'));

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
