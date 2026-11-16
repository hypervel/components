<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Recaller;
use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Routing\Router;
use Hypervel\Session\Middleware\AuthenticateSession;
use Hypervel\Support\Facades\Auth;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

#[WithMigration]
#[WithConfig('app.key', 'base64:IUHRqAQ99pZ0A1MPjbuv1D6ff3jxv0GIvS2qIW4JNU4=')]
class AuthenticateSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('auth.providers.users.model', User::class);

        $app->make('config')->set('auth.guards.unchecked', ['driver' => 'unchecked-session', 'provider' => 'users']);

        Auth::extend('unchecked-session', function (ApplicationContract $app, string $name, array $config): UncheckedRecallerSessionGuard {
            $guard = new UncheckedRecallerSessionGuard(
                $name,
                Auth::createUserProvider($config['provider']),
                $app->make('session.store'),
                $app,
                hashKey: $app->make('config')->string('app.key'),
            );

            $guard->setCookieJar($app->make('cookie'));

            return $guard;
        });
    }

    /**
     * Define the test routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('login', fn (): string => 'login')->name('login');
        $router->get('without-session', fn (): string => 'ok')->middleware(AuthenticateSession::class);
        // Remember-login state belongs to the request coroutine, so expose it in the response.
        $router->get('protected', fn (): string => Auth::guard()->viaRemember() ? 'remembered' : 'ok')
            ->middleware(['web', AuthenticateSession::class]);
    }

    public function testItPassesThroughWithoutASession(): void
    {
        $this->actingAs(UserFactory::new()->create());

        $this->get('without-session')->assertOk();
    }

    public function testItPassesThroughForGuests(): void
    {
        $this->get('protected')->assertOk()->assertSessionMissing('password_hash_web');
    }

    public function testItPassesThroughForUsersWithoutAPassword(): void
    {
        $this->actingAs(UserFactory::new()->make(['password' => null]));

        $this->get('protected')->assertOk()->assertSessionMissing('password_hash_web');
    }

    public function testItStoresThePasswordHashInTheSession(): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user)->get('protected')
            ->assertOk()
            ->assertSessionHas('password_hash_web', $this->hashFor($user));
    }

    #[DataProvider('authenticatedRememberCookies')]
    public function testItKeepsTheSessionWhenThePasswordHashMatches(bool $malformedCookie): void
    {
        $user = UserFactory::new()->create();

        if ($malformedCookie) {
            $this->withCookie(Auth::guard('web')->getRecallerName(), 'a|b');
        }

        $this->actingAs($user)
            ->withSession(['password_hash_web' => $this->hashFor($user), 'a' => '1'])
            ->get('protected')
            ->assertOk()
            ->assertSessionHas(['password_hash_web' => $this->hashFor($user), 'a' => '1']);
    }

    /**
     * Provide cookies that must not affect an already authenticated session.
     */
    public static function authenticatedRememberCookies(): array
    {
        return ['no cookie' => [false], 'malformed cookie' => [true]];
    }

    public function testItLogsOutWhenThePasswordHashDoesNotMatch(): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->withSession(['password_hash_web' => 'invalid-password', 'a' => '1'])
            ->getJson('protected')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertSessionMissing('password_hash_web')
            ->assertSessionMissing('a');

        $this->assertGuest();
    }

    public function testItRedirectsUsingTheConfiguredCallback(): void
    {
        // The default redirect is registered once the HTTP kernel is resolved...
        $this->app->make(Kernel::class);

        AuthenticateSession::redirectUsing(fn (): string => '/i-wanna-go-home');

        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->withSession(['password_hash_web' => 'invalid-password'])
            ->get('protected')
            ->assertRedirect('/i-wanna-go-home');
    }

    public function testItAcceptsAValidRememberCookie(): void
    {
        $user = UserFactory::new()->create();

        $this->withRecaller($user, $this->hashFor($user))
            ->withSession(['password_hash_web' => $this->hashFor($user), 'a' => '1'])
            ->get('protected')
            ->assertOk()
            ->assertSessionHas('a', '1')
            ->assertSeeText('remembered');
    }

    public function testAuthManagerRedirectGuestsToConfiguresSessionMismatchRedirect(): void
    {
        $this->app->make(Kernel::class);
        Auth::redirectGuestsTo('/session-mismatch');

        // Isolate AuthenticateSession's own slot so this tests the aggregate's direct registration.
        AuthenticationException::flushState();

        $this->actingAs(UserFactory::new()->create())
            ->withSession(['password_hash_web' => 'invalid-password'])
            ->get('protected')
            ->assertRedirect('/session-mismatch');
    }

    public function testItLogsOutWhenTheRememberedSessionHashDoesNotMatch(): void
    {
        $user = UserFactory::new()->create();

        $this->withRecaller($user, $this->hashFor($user))
            ->withSession(['password_hash_web' => 'invalid-password', 'a' => '1'])
            ->get('protected')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('password_hash_web')
            ->assertSessionMissing('a');
    }

    public function testItIgnoresARememberCookieWithAStaleHash(): void
    {
        $user = UserFactory::new()->create();

        $this->withRecaller($user, $this->hashFor($user) . '-stale')
            ->withSession(['a' => '1'])
            ->get('protected')
            ->assertOk()
            ->assertSessionHas('a', '1')
            ->assertSessionMissing('password_hash_web');

        $this->assertGuest();
    }

    #[DataProvider('invalidRememberHashes')]
    public function testItLogsOutWhenACustomGuardRemembersTheUserWithoutCheckingTheCookieHash(bool $rawHash): void
    {
        $user = UserFactory::new()->create();

        $this->app->make('config')->set('auth.defaults.guard', 'unchecked');

        $name = Auth::guard('unchecked')->getRecallerName();

        $hash = $rawHash ? $user->password : 'stale-hash';

        // The built-in guard rejects a stale hash itself, but a custom guard might not...
        $this->withCookie($name, "{$user->getAuthIdentifier()}|{$user->getRememberToken()}|{$hash}")
            ->withSession(['a' => '1'])
            ->get('protected')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('a');
    }

    /**
     * Provide unsupported remember-cookie hashes.
     */
    public static function invalidRememberHashes(): array
    {
        return ['stale hash' => [false], 'raw hash' => [true]];
    }

    // REMOVED: Laravel's raw-hash upgrade and guards without hashPasswordForCookie();
    // Hypervel supports only HMAC session and remember-cookie artifacts.

    #[DataProvider('invalidSessionHashes')]
    public function testItRejectsRawOrMalformedSessionHashes(bool $rawHash): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->withSession(['password_hash_web' => $rawHash ? $user->password : ['not-a-password-hash'], 'a' => '1'])
            ->getJson('protected')
            ->assertUnauthorized()
            ->assertSessionMissing('password_hash_web')
            ->assertSessionMissing('a');

        $this->assertGuest();
    }

    /**
     * Provide unsupported password-hash representations.
     */
    public static function invalidSessionHashes(): array
    {
        return ['raw hash' => [true], 'malformed hash' => [false]];
    }

    /**
     * Get the password hash stored in session artifacts.
     */
    protected function hashFor(User $user): string
    {
        return Auth::guard('web')->hashPasswordForCookie($user->password);
    }

    /**
     * Add the remember cookie for the user.
     */
    protected function withRecaller(User $user, string $hash): static
    {
        $name = Auth::guard('web')->getRecallerName();

        return $this->withCookie($name, "{$user->getAuthIdentifier()}|{$user->getRememberToken()}|{$hash}");
    }
}

class UncheckedRecallerSessionGuard extends SessionGuard
{
    /**
     * Recall the user without verifying the password hash.
     */
    protected function userFromRecaller(Recaller $recaller): ?Authenticatable
    {
        if (! $recaller->valid() || $this->getContextState('recallAttempted', false)) {
            return null;
        }

        $this->setContextState('recallAttempted', true);

        $user = $this->provider->retrieveByToken(
            $recaller->id(),
            $recaller->token()
        );

        $this->setContextState('viaRemember', $user !== null);

        return $user;
    }
}
