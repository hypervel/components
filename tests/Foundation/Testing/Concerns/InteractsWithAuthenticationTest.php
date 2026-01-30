<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Auth\Authenticatable as UserContract;
use Hypervel\Contracts\Auth\Factory as AuthFactoryContract;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Hypervel\Testbench\TestCase;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithAuthenticationTest extends TestCase
{
    use InteractsWithAuthentication;

    public function tearDown(): void
    {
        parent::tearDown();

        Context::destroy('__auth.defaults.guard');
    }

    public function testAssertAsGuest()
    {
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('check')
            ->twice()
            ->andReturn(false);

        $this->app->get(AuthFactoryContract::class)
            ->extend('foo', fn () => $guard);
        $this->app->get(ConfigInterface::class)
            ->set('auth.guards.foo', [
                'driver' => 'foo',
                'provider' => 'users',
            ]);

        Context::set('__auth.defaults.guard', 'foo');

        $this->assertGuest();
        $this->assertFalse($this->isAuthenticated());
    }

    public function testAssertActingAs()
    {
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('check')
            ->once()
            ->andReturn(true);
        $guard->shouldReceive('setUser')
            ->once()
            ->andReturn($user = Mockery::mock(UserContract::class));
        $guard->shouldReceive('user')
            ->once()
            ->andReturn($user);
        $user->shouldReceive('getAuthIdentifier')
            ->twice()
            ->andReturn('id');

        $this->app->get(AuthFactoryContract::class)
            ->extend('foo', fn () => $guard);
        $this->app->get(ConfigInterface::class)
            ->set('auth.guards.foo', [
                'driver' => 'foo',
                'provider' => 'users',
            ]);

        Context::set('__auth.defaults.guard', 'foo');

        $this->actingAs($user);

        $this->assertTrue($this->isAuthenticated());
        $this->assertAuthenticatedAs($user);
    }
}
