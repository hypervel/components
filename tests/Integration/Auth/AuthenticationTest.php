<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Events\Dispatcher;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Auth\Fixtures\AuthTestUser;
use Override;
use SensitiveParameter;

class AuthenticationTest extends TestCase
{
    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('auth.providers.users.model', AuthTestUser::class);
    }

    public function testResolvedSessionGuardFollowsTheActiveEventDispatcher(): void
    {
        $guard = $this->app->make(AuthManager::class)->guard();

        $this->assertInstanceOf(SessionGuard::class, $guard);
        $this->assertInstanceOf(Dispatcher::class, $guard->getDispatcher());

        Event::fake();

        $this->assertInstanceOf(EventFake::class, $guard->getDispatcher());
    }

    public function testEventRebindingLeavesDispatcherlessCustomGuardsIntact(): void
    {
        $config = $this->app->make('config');
        $config->set('auth.guards.custom', [
            'driver' => 'custom-without-dispatcher',
            'provider' => 'users',
        ]);

        $auth = $this->app->make(AuthManager::class);
        $auth->extend('custom-without-dispatcher', fn () => new AuthenticationTestDispatcherlessGuard);
        $guard = $auth->guard('custom');

        Event::fake();

        $this->assertSame($guard, $auth->guard('custom'));
    }
}

class AuthenticationTestDispatcherlessGuard implements Guard
{
    protected ?Authenticatable $user = null;

    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool
    {
        return $this->user !== null;
    }

    /**
     * Determine if the current user is a guest.
     */
    public function guest(): bool
    {
        return ! $this->check();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id(): int|string|null
    {
        return $this->user?->getAuthIdentifier();
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(#[SensitiveParameter] array $credentials = []): bool
    {
        return false;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }
}
