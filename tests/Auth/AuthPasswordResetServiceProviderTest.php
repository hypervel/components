<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Closure;
use Hypervel\Auth\Passwords\PasswordBrokerManager;
use Hypervel\Auth\Passwords\PasswordResetServiceProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;

class AuthPasswordResetServiceProviderTest extends TestCase
{
    public function testEventRebindDoesNotResolveAnUnusedPasswordManager(): void
    {
        [$application, $callback] = $this->registerProvider();
        $events = m::mock(Dispatcher::class);
        $application->shouldReceive('resolved')->once()->with('auth.password')->andReturnFalse();
        $application->shouldNotReceive('make')->with('auth.password');

        $callback($application, $events);
    }

    public function testEventRebindRefreshesAnAlreadyResolvedPasswordManager(): void
    {
        [$application, $callback] = $this->registerProvider();
        $events = m::mock(Dispatcher::class);
        $manager = m::mock(PasswordBrokerManager::class);
        $application->shouldReceive('resolved')->once()->with('auth.password')->andReturnTrue();
        $application->shouldReceive('make')->once()->with('auth.password')->andReturn($manager);
        $manager->shouldReceive('refreshEventDispatcher')->once()->with($events);

        $callback($application, $events);
    }

    /**
     * Register the provider and return its event-rebind callback.
     *
     * @return array{Application&m\MockInterface, Closure}
     */
    private function registerProvider(): array
    {
        $callback = null;
        $application = m::mock(Application::class);
        $application->shouldReceive('singleton')
            ->once()
            ->with('auth.password', m::type(Closure::class));
        $application->shouldReceive('bind')
            ->once()
            ->with('auth.password.broker', m::type(Closure::class));
        $application->shouldReceive('rebinding')
            ->once()
            ->with('events', m::on(function (mixed $registered) use (&$callback): bool {
                $callback = $registered;

                return $registered instanceof Closure;
            }));

        (new PasswordResetServiceProvider($application))->register();

        $this->assertInstanceOf(Closure::class, $callback);

        return [$application, $callback];
    }
}
