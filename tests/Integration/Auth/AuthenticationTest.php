<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Auth\Events\Attempting;
use Hypervel\Auth\Events\Authenticated;
use Hypervel\Auth\Events\Failed;
use Hypervel\Auth\Events\Login;
use Hypervel\Auth\Events\Logout;
use Hypervel\Auth\Events\OtherDeviceLogout;
use Hypervel\Auth\Events\Validated;
use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Auth\Fixtures\AuthTestUser;
use InvalidArgumentException;
use Override;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

#[WithMigration]
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Define the authentication test environment.
     */
    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'app.key' => '12345678901234567890123456789012',
            'auth.providers.users.model' => AuthTestUser::class,
            'auth.timebox_duration' => 0,
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 4,
        ]);
    }

    /**
     * Define the basic authentication routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('basic', function (): Response|string {
            return $this->app->make('auth')->guard()->basic()
                ?: $this->app->make('auth')->user()->toJson();
        });

        $router->get('basicWithCondition', function (): Response|string {
            return $this->app->make('auth')->guard()->basic('email', ['is_active' => true])
                ?: $this->app->make('auth')->user()->toJson();
        });
    }

    /**
     * Prepare the users table and initial user.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('name', 'username');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->tinyInteger('is_active')->default(0);
        });

        AuthTestUser::create([
            'username' => 'username',
            'email' => 'email',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    public function testBasicAuthProtectsRoute(): void
    {
        $this->get('basic')->assertStatus(401);
    }

    public function testBasicAuthPassesOnCorrectCredentials(): void
    {
        $response = $this->get('basic', [
            'Authorization' => 'Basic ' . base64_encode('email:password'),
        ]);

        $response->assertStatus(200);
        $this->assertSame('email', $response->json()['email']);
    }

    public function testBasicAuthRespectsAdditionalConditions(): void
    {
        AuthTestUser::create([
            'username' => 'username2',
            'email' => 'email2',
            'password' => bcrypt('password'),
            'is_active' => false,
        ]);

        $this->get('basicWithCondition', [
            'Authorization' => 'Basic ' . base64_encode('email2:password'),
        ])->assertStatus(401);

        $this->get('basicWithCondition', [
            'Authorization' => 'Basic ' . base64_encode('email:password'),
        ])->assertStatus(200);
    }

    public function testBasicAuthFailsOnWrongCredentials(): void
    {
        $this->get('basic', [
            'Authorization' => 'Basic ' . base64_encode('email:wrong_password'),
        ])->assertStatus(401);
    }

    public function testLoggingInFailsViaAttempt(): void
    {
        Event::fake();

        $this->assertFalse(
            $this->app->make('auth')->attempt(['email' => 'wrong', 'password' => 'password'])
        );

        $this->assertFalse($this->app->make('auth')->check());
        $this->assertNull($this->app->make('auth')->user());

        Event::assertDispatched(Attempting::class, function (Attempting $event): bool {
            $this->assertSame('web', $event->guard);
            $this->assertEquals(['email' => 'wrong', 'password' => 'password'], $event->credentials);

            return true;
        });
        Event::assertNotDispatched(Validated::class);

        Event::assertDispatched(Failed::class, function (Failed $event): bool {
            $this->assertSame('web', $event->guard);
            $this->assertEquals(['email' => 'wrong', 'password' => 'password'], $event->credentials);
            $this->assertNull($event->user);

            return true;
        });
    }

    public function testLoggingInSucceedsViaAttempt(): void
    {
        Event::fake();

        $this->assertTrue(
            $this->app->make('auth')->attempt(['email' => 'email', 'password' => 'password'])
        );
        $this->assertInstanceOf(AuthTestUser::class, $this->app->make('auth')->user());
        $this->assertTrue($this->app->make('auth')->check());

        Event::assertDispatched(Attempting::class, function (Attempting $event): bool {
            $this->assertSame('web', $event->guard);
            $this->assertEquals(['email' => 'email', 'password' => 'password'], $event->credentials);

            return true;
        });
        Event::assertDispatched(Validated::class, function (Validated $event): bool {
            $this->assertSame('web', $event->guard);
            $this->assertEquals(1, $event->user->id);

            return true;
        });
        Event::assertDispatched(Login::class, function (Login $event): bool {
            $this->assertSame('web', $event->guard);
            $this->assertEquals(1, $event->user->id);

            return true;
        });
        Event::assertDispatched(Authenticated::class, function (Authenticated $event): bool {
            $this->assertSame('web', $event->guard);
            $this->assertEquals(1, $event->user->id);

            return true;
        });
    }

    public function testLoggingInUsingId(): void
    {
        $this->app->make('auth')->loginUsingId(1);
        $this->assertEquals(1, $this->app->make('auth')->user()->id);

        $this->assertFalse($this->app->make('auth')->loginUsingId(1000));
    }

    public function testLoggingOut(): void
    {
        Event::fake();

        $this->app->make('auth')->loginUsingId(1);
        $this->assertEquals(1, $this->app->make('auth')->user()->id);

        $this->app->make('auth')->logout();
        $this->assertNull($this->app->make('auth')->user());
        Event::assertDispatched(Logout::class, function (Logout $event): bool {
            $this->assertSame('web', $event->guard);
            $this->assertEquals(1, $event->user->id);

            return true;
        });
    }

    public function testLoggingOutOtherDevices(): void
    {
        Event::fake();

        $this->app->make('auth')->loginUsingId(1);

        $user = $this->app->make('auth')->user();

        $this->assertEquals(1, $user->id);

        $this->app->make('auth')->logoutOtherDevices('password');
        $this->assertEquals(1, $user->id);

        Event::assertDispatched(OtherDeviceLogout::class, function (OtherDeviceLogout $event): bool {
            $this->assertSame('web', $event->guard);
            $this->assertEquals(1, $event->user->id);

            return true;
        });
    }

    public function testPasswordMustBeValidToLogOutOtherDevices(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('current password'));

        $this->app->make('auth')->loginUsingId(1);

        $user = $this->app->make('auth')->user();

        $this->assertEquals(1, $user->id);

        $this->app->make('auth')->logoutOtherDevices('adifferentpassword');
    }

    public function testLoggingInOutViaAttemptRemembering(): void
    {
        $this->assertTrue(
            $this->app->make('auth')->attempt(['email' => 'email', 'password' => 'password'], true)
        );
        $this->assertInstanceOf(AuthTestUser::class, $this->app->make('auth')->user());
        $this->assertTrue($this->app->make('auth')->check());
        $this->assertNotNull($this->app->make('auth')->user()->getRememberToken());

        $oldToken = $this->app->make('auth')->user()->getRememberToken();
        $user = $this->app->make('auth')->user();

        $this->app->make('auth')->logout();

        $this->assertNotNull($user->getRememberToken());
        $this->assertNotEquals($oldToken, $user->getRememberToken());
    }

    public function testLoggingInOutCurrentDeviceViaRemembering(): void
    {
        $this->assertTrue(
            $this->app->make('auth')->attempt(['email' => 'email', 'password' => 'password'], true)
        );
        $this->assertInstanceOf(AuthTestUser::class, $this->app->make('auth')->user());
        $this->assertTrue($this->app->make('auth')->check());
        $this->assertNotNull($this->app->make('auth')->user()->getRememberToken());

        $oldToken = $this->app->make('auth')->user()->getRememberToken();
        $user = $this->app->make('auth')->user();

        $this->app->make('auth')->logoutCurrentDevice();

        $this->assertNotNull($user->getRememberToken());
        $this->assertEquals($oldToken, $user->getRememberToken());
    }

    public function testAuthViaAttemptRemembering(): void
    {
        $provider = new EloquentUserProvider(app('hash'), AuthTestUser::class);

        $user = AuthTestUser::create([
            'username' => 'username2',
            'email' => 'email2',
            'password' => bcrypt('password'),
            'remember_token' => $token = Str::random(),
            'is_active' => false,
        ]);

        $this->assertEquals($user->id, $provider->retrieveByToken($user->id, $token)->id);

        $user->update([
            'remember_token' => null,
        ]);

        $this->assertNull($provider->retrieveByToken($user->id, $token));
    }

    public function testResolvedSessionGuardFollowsTheActiveEventDispatcher(): void
    {
        $guard = $this->app->make(AuthManager::class)->guard();

        $this->assertInstanceOf(SessionGuard::class, $guard);
        $this->assertInstanceOf(Dispatcher::class, $guard->getDispatcher());

        Event::fake();

        $this->assertInstanceOf(EventFake::class, $guard->getDispatcher());
    }

    public function testDispatcherChangesIfThereIsOneOnTheCustomAuthGuard(): void
    {
        $this->app->make('config')->set('auth.guards.myGuard', [
            'driver' => 'myCustomDriver',
            'provider' => 'users',
        ]);

        $auth = $this->app->make(AuthManager::class);
        $auth->extend('myCustomDriver', fn (): AuthenticationTestCustomGuard => new AuthenticationTestCustomGuard);
        $guard = $auth->guard('myGuard');

        $this->assertInstanceOf(AuthenticationTestCustomGuard::class, $guard);
        $this->assertInstanceOf(Dispatcher::class, $guard->getDispatcher());

        Event::fake();

        $this->assertSame($guard, $auth->guard('myGuard'));
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

class AuthenticationTestCustomGuard extends AuthenticationTestDispatcherlessGuard
{
    protected DispatcherContract $events;

    /**
     * Create a guard with an event dispatcher.
     */
    public function __construct()
    {
        $this->setDispatcher(new Dispatcher);
    }

    /**
     * Set the event dispatcher.
     */
    public function setDispatcher(DispatcherContract $events): void
    {
        $this->events = $events;
    }

    /**
     * Get the event dispatcher.
     */
    public function getDispatcher(): DispatcherContract
    {
        return $this->events;
    }
}
