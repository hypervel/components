<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Closure;
use Hypervel\Auth\Passwords\PasswordBrokerManager;
use Hypervel\Auth\Passwords\PasswordResetServiceProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JMac\Testing\OverriddenDouble;

class AuthPasswordResetServiceProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testEventRebindDoesNotResolveAnUnusedPasswordManager(): void
    {
        [$application, $callback] = $this->registerProvider();
        $events = Double::for(Dispatcher::class)->strict();
        $application->expects('resolved')->with('auth.password')->returns(false);
        $application->expects('make')->never()->with('auth.password');

        $callback($application->instance(), $events);
    }

    public function testEventRebindRefreshesAnAlreadyResolvedPasswordManager(): void
    {
        [$application, $callback] = $this->registerProvider();
        $events = Double::for(Dispatcher::class)->strict();
        $manager = Double::for(PasswordBrokerManager::class)->strict();
        $application->expects('resolved')->with('auth.password')->returns(true);
        $application->expects('make')->with('auth.password')->returns($manager);
        $manager->expects('refreshEventDispatcher')->with(Argument::same($events));

        $callback($application->instance(), $events);
    }

    /**
     * Register the provider and return its event-rebind callback.
     *
     * @return array{OverriddenDouble, Closure}
     */
    private function registerProvider(): array
    {
        $callback = null;
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('singleton')
            ->with('auth.password', Argument::type(Closure::class));
        $application->expects('bind')
            ->with('auth.password.broker', Argument::type(Closure::class));
        $application->expects('rebinding')
            ->with('events', Argument::capture($callback));

        (new PasswordResetServiceProvider($application->instance()))->register();

        $this->assertInstanceOf(Closure::class, $callback);

        return [$application, $callback];
    }
}
