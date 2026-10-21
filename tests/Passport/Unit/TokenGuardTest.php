<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Firebase\JWT\JWT;
use Hypervel\Auth\AuthManager;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Encryption\Encrypter as EncrypterContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Cookie\CookieValuePrefix;
use Hypervel\Encryption\Encrypter;
use Hypervel\Http\Request;
use Hypervel\Passport\ApiTokenCookieFactory;
use Hypervel\Passport\Bridge\BearerRequestFactory;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Guards\TokenGuard;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportUserProvider;
use Hypervel\Passport\ServerFactory;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Passport\Fixtures\User as TokenGuardTestUser;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class TokenGuardTest extends TestCase
{
    // REMOVED: User/client integer-ID collisions require the pre-13 client schema.

    /**
     * Load the guard's configuration without registering the OAuth endpoints.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('passport', require __DIR__ . '/../../../src/passport/config/passport.php');
    }

    #[TestWith([null])]
    #[TestWith(['Basic dXNlcjpwYXNzd29yZA=='])]
    public function testUsersMayBeRetrievedFromCookiesWithCsrfTokenHeader(?string $authorization): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $key = hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie');

        $request = Request::create('/');
        $request->headers->set('X-CSRF-TOKEN', 'token');
        $request->headers->set('Authorization', $authorization);
        $request->cookies->set(
            'hypervel_token',
            $encrypter->encrypt(CookieValuePrefix::create('hypervel_token', $encrypter->getKey()) . JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'csrf' => 'token',
                'exp' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            ], $key, 'HS256'), false)
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->twice()->with(1)->andReturn($expectedUser = new TokenGuardTestUser);

        $user = $guard->user();

        $this->assertEquals($expectedUser, $user);
        $this->assertSame($user, $guard->user());
        $this->assertTrue($guard->hasUser());
        $this->assertTrue($guard->validate(['request' => $request]));
        $this->assertNull($guard->client());
        $this->assertNull($guard->token());
    }

    public function testUsersMayBeRetrievedFromCookiesWithXsrfTokenHeader(): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $key = hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie');

        $request = Request::create('/');
        $request->headers->set('X-XSRF-TOKEN', $encrypter->encrypt(CookieValuePrefix::create('X-XSRF-TOKEN', $encrypter->getKey()) . 'token', false));
        $request->cookies->set(
            'hypervel_token',
            $encrypter->encrypt(CookieValuePrefix::create('hypervel_token', $encrypter->getKey()) . JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'csrf' => 'token',
                'exp' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            ], $key, 'HS256'), false)
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->with(1)->andReturn($expectedUser = new TokenGuardTestUser);

        $user = $guard->user();

        $this->assertEquals($expectedUser, $user);
    }

    public function testCookieXsrfIsVerifiedAgainstCsrfTokenHeader(): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $key = hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie');

        $request = Request::create('/');
        $request->headers->set('X-CSRF-TOKEN', 'wrong_token');
        $request->cookies->set(
            'hypervel_token',
            $encrypter->encrypt(CookieValuePrefix::create('hypervel_token', $encrypter->getKey()) . JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'csrf' => 'token',
                'exp' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            ], $key, 'HS256'), false)
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->never();

        $this->assertNull($guard->user());
    }

    public function testCookieXsrfIsVerifiedAgainstXsrfTokenHeader(): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $key = hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie');

        $request = Request::create('/');
        $request->headers->set('X-XSRF-TOKEN', $encrypter->encrypt(CookieValuePrefix::create('X-XSRF-TOKEN', $encrypter->getKey()) . 'wrong_token', false));
        $request->cookies->set(
            'hypervel_token',
            $encrypter->encrypt(CookieValuePrefix::create('hypervel_token', $encrypter->getKey()) . JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'csrf' => 'token',
                'exp' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            ], $key, 'HS256'), false)
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->never();

        $this->assertNull($guard->user());
    }

    public function testUsersMayBeRetrievedFromCookiesWithXsrfTokenHeaderWhenUsingACustomEncryptionKey(): void
    {
        Passport::encryptTokensUsing(function (EncrypterContract $encrypter): string {
            return $encrypter->getKey() . '.mykey';
        });

        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');

        $request = Request::create('/');
        $request->headers->set('X-XSRF-TOKEN', $encrypter->encrypt(CookieValuePrefix::create('X-XSRF-TOKEN', $encrypter->getKey()) . 'token', false));
        $request->cookies->set(
            'hypervel_token',
            $encrypter->encrypt(CookieValuePrefix::create('hypervel_token', $encrypter->getKey()) . JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'csrf' => 'token',
                'exp' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            ], hash_hkdf('sha256', Passport::tokenEncryptionKey($encrypter), 32, 'hypervel-passport-cookie'), 'HS256'), false)
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->with(1)->andReturn($expectedUser = new TokenGuardTestUser);

        $user = $guard->user();

        $this->assertEquals($expectedUser, $user);
    }

    public function testUsersMayBeRetrievedFromCookiesWithoutEncryption(): void
    {
        Passport::withoutCookieEncryption();
        Passport::encryptTokensUsing(function (EncrypterContract $encrypter): string {
            return $encrypter->getKey() . '.mykey';
        });

        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');

        $request = Request::create('/');
        $request->headers->set('X-XSRF-TOKEN', $encrypter->encrypt(CookieValuePrefix::create('X-XSRF-TOKEN', $encrypter->getKey()) . 'token', false));
        $request->cookies->set(
            'hypervel_token',
            JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'csrf' => 'token',
                'exp' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            ], hash_hkdf('sha256', Passport::tokenEncryptionKey($encrypter), 32, 'hypervel-passport-cookie'), 'HS256')
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->with(1)->andReturn($expectedUser = new TokenGuardTestUser);

        $user = $guard->user();

        $this->assertEquals($expectedUser, $user);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testArrayCookiesAreRejected(bool $encrypted): void
    {
        if (! $encrypted) {
            Passport::withoutCookieEncryption();
        }

        $userProvider = m::mock(PassportUserProvider::class);
        $userProvider->shouldNotReceive('retrieveById');
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $request = Request::create('/', cookies: [Passport::cookie() => ['x' => '1']]);
        $guard = $this->makeGuard($userProvider, m::mock(ClientRepository::class), $encrypter, $request);

        $this->assertNull($guard->user());
        $this->assertFalse($guard->validate(['request' => $request]));
    }

    public function testXsrfTokenCookieWithoutATokenHeaderIsNotAccepted(): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $key = hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie');

        $request = Request::create('/');
        $request->cookies->set('XSRF-TOKEN', $encrypter->encrypt('token', false));
        $request->cookies->set(
            'hypervel_token',
            $encrypter->encrypt(CookieValuePrefix::create('hypervel_token', $encrypter->getKey()) . JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'csrf' => 'token',
                'exp' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            ], $key, 'HS256'), false)
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->never();

        $this->assertNull($guard->user());
    }

    public function testExpiredCookiesMayNotBeUsed(): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $key = hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie');

        $request = Request::create('/');
        $request->headers->set('X-CSRF-TOKEN', 'token');
        $request->cookies->set(
            'hypervel_token',
            $encrypter->encrypt(CookieValuePrefix::create('hypervel_token', $encrypter->getKey()) . JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'csrf' => 'token',
                'exp' => CarbonImmutable::now()->subMinutes(10)->getTimestamp(),
            ], $key, 'HS256'), false)
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->never();

        $this->assertNull($guard->user());
    }

    public function testCsrfCheckCanBeDisabled(): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $key = hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie');

        Passport::ignoreCsrfToken();

        $request = Request::create('/');
        $request->cookies->set(
            'hypervel_token',
            $encrypter->encrypt(CookieValuePrefix::create('hypervel_token', $encrypter->getKey()) . JWT::encode([
                'sub' => 1,
                'provider' => 'users',
                'exp' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            ], $key, 'HS256'), false)
        );

        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);

        $userProvider->expects('retrieveById')->with(1)->andReturn($expectedUser = new TokenGuardTestUser);

        $user = $guard->user();

        $this->assertEquals($expectedUser, $user);
    }

    // REMOVED: Cookie client lookup; factory cookies carry no aud claim and have no OAuth client.
    public function testCookiesHaveNoOauthClient(): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $clients = m::mock(ClientRepository::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');

        $request = Request::create('/');
        $request->headers->set('X-CSRF-TOKEN', 'token');
        $guard = $this->makeGuard($userProvider, $clients, $encrypter, $request);
        $servers = new ServerFactory($this->app, $this->app->make('config'), $encrypter, $this->app->make(BearerRequestFactory::class));
        $factory = new ApiTokenCookieFactory($this->app->make('config'), $servers, $this->app->make(AuthManager::class), $this->app);
        $cookie = $factory->make(1, 'token');
        $request->cookies->set($cookie->getName(), $encrypter->encrypt(
            CookieValuePrefix::create($cookie->getName(), $encrypter->getKey()) . $cookie->getValue(),
            false
        ));
        $userProvider->expects('retrieveById')->with(1)->andReturn(new TokenGuardTestUser);
        $clients->shouldNotReceive('findActive');

        $this->assertInstanceOf(TokenGuardTestUser::class, $guard->user());
        $this->assertNull($guard->client());
        $this->assertNull($guard->token());
    }

    #[TestWith([[]])]
    #[TestWith([['provider' => null]])]
    #[TestWith([['provider' => 'admins']])]
    public function testCookiesRequireTheGuardsProvider(array $providerClaim): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $userProvider->shouldNotReceive('retrieveById');
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $request = Request::create('/');
        $request->headers->set('X-CSRF-TOKEN', 'token');
        $request->cookies->set(Passport::cookie(), $encrypter->encrypt(
            CookieValuePrefix::create(Passport::cookie(), $encrypter->getKey()) . JWT::encode([
                ...$providerClaim,
                'sub' => 1,
                'csrf' => 'token',
                'exp' => time() + 600,
            ], hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie'), 'HS256'),
            false
        ));

        $guard = $this->makeGuard($userProvider, m::mock(ClientRepository::class), $encrypter, $request);

        $this->assertNull($guard->user());
    }

    #[DataProvider('cookieBindings')]
    public function testCookieBindingIsCheckedBeforeLoadingTheUser(array $bindingClaim, ?string $binding, bool $accepted): void
    {
        Passport::bindCookiesUsing(static fn (Request $request): ?string => $request->headers->get('X-Organization'));
        $userProvider = m::mock(PassportUserProvider::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $request = Request::create('/');
        $request->headers->set('X-Organization', $binding);
        $request->headers->set('X-CSRF-TOKEN', 'token');
        $request->cookies->set(Passport::cookie(), $encrypter->encrypt(
            CookieValuePrefix::create(Passport::cookie(), $encrypter->getKey()) . JWT::encode([
                ...$bindingClaim,
                'provider' => 'users',
                'sub' => 1,
                'csrf' => 'token',
                'exp' => time() + 600,
            ], hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie'), 'HS256'),
            false
        ));
        $expected = $accepted ? new TokenGuardTestUser : null;

        if ($accepted) {
            $userProvider->expects('retrieveById')->with(1)->andReturn($expected);
        } else {
            $userProvider->shouldNotReceive('retrieveById');
        }

        $guard = $this->makeGuard($userProvider, m::mock(ClientRepository::class), $encrypter, $request);

        $this->assertSame($expected, $guard->user());

        if ($accepted) {
            $replacement = clone $request;
            $replacement->headers->set('X-Organization', 'another');
            RequestContext::set($replacement);

            $this->assertFalse($guard->hasUser());
            $this->assertNull($guard->user());
        }
    }

    /**
     * Provide matching and mismatched cookie contexts.
     */
    public static function cookieBindings(): array
    {
        return [
            [['bind' => 'first'], 'first', true],
            [['bind' => 'first'], 'second', false],
            [[], 'first', false],
            [['bind' => null], null, true],
        ];
    }

    public function testUserProviderFailuresAreNotTreatedAsInvalidCookies(): void
    {
        $userProvider = m::mock(PassportUserProvider::class);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $request = Request::create('/');
        $request->headers->set('X-CSRF-TOKEN', 'token');
        $request->cookies->set(Passport::cookie(), $encrypter->encrypt(
            CookieValuePrefix::create(Passport::cookie(), $encrypter->getKey()) . JWT::encode([
                'provider' => 'users',
                'sub' => 1,
                'csrf' => 'token',
                'exp' => time() + 600,
            ], hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie'), 'HS256'),
            false
        ));
        $failure = new RuntimeException('User provider unavailable.');
        $userProvider->expects('retrieveById')->with(1)->andThrow($failure);
        $guard = $this->makeGuard($userProvider, m::mock(ClientRepository::class), $encrypter, $request);

        $this->expectExceptionObject($failure);

        $guard->user();
    }

    /**
     * Create the guard with real key derivation and the coroutine's request.
     */
    protected function makeGuard(PassportUserProvider $provider, ClientRepository $clients, EncrypterContract $encrypter, Request $request): TokenGuard
    {
        RequestContext::set($request);
        $provider->allows('getProviderName')->andReturn('users');

        return new TokenGuard(
            'passport',
            new ServerFactory($this->app, $this->app->make('config'), $encrypter, $this->app->make(BearerRequestFactory::class)),
            $provider,
            $clients,
            $encrypter,
            $this->app,
        );
    }
}
