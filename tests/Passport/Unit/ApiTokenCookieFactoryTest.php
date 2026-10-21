<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hypervel\Auth\AuthManager;
use Hypervel\Config\Repository;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Encryption\Encrypter as EncrypterContract;
use Hypervel\Encryption\Encrypter;
use Hypervel\Http\Request;
use Hypervel\Passport\ApiTokenCookieFactory;
use Hypervel\Passport\Bridge\BearerRequestFactory;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;

class ApiTokenCookieFactoryTest extends TestCase
{
    #[DataProvider('ciphers')]
    public function testCookieCanBeSuccessfullyCreated(string $cipher, int $keyLength, bool $expireOnClose): void
    {
        $config = new Repository(['session' => [
            'lifetime' => 120,
            'expire_on_close' => $expireOnClose,
            'path' => '/',
            'domain' => null,
            'secure' => true,
            'same_site' => 'lax',
            'partitioned' => false,
        ]]);
        $encrypter = new Encrypter(str_repeat('a', $keyLength), $cipher);
        $factory = $this->makeFactory($config, $encrypter);

        $cookie = $factory->make(1, 'token');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $claims = JWT::decode($cookie->getValue(), new Key(
            hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie'),
            'HS256'
        ));
        $this->assertSame(1, $claims->sub);
        $this->assertSame('token', $claims->csrf);
        $this->assertSame('users', $claims->provider);
        $this->assertEqualsWithDelta(time() + 7200, $claims->exp, 2);
        $this->assertSame($expireOnClose ? 0 : $claims->exp, $cookie->getExpiresTime());
        $this->assertFalse(property_exists($claims, 'bind'));
    }

    /**
     * Provide supported application key sizes.
     */
    public static function ciphers(): array
    {
        return [['aes-256-cbc', 32, false], ['aes-128-cbc', 16, false], ['aes-256-cbc', 32, true]];
    }

    public function testCookieCanBeSuccessfullyCreatedWhenUsingACustomEncryptionKey(): void
    {
        Passport::encryptTokensUsing(function (EncrypterContract $encrypter): string {
            return $encrypter->getKey() . '.mykey';
        });

        $config = new Repository(['session' => [
            'lifetime' => 120,
            'expire_on_close' => false,
            'path' => '/',
            'domain' => null,
            'secure' => true,
            'same_site' => 'lax',
            'partitioned' => false,
        ]]);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $factory = $this->makeFactory($config, $encrypter);

        $cookie = $factory->make(1, 'token');

        $this->assertInstanceOf(Cookie::class, $cookie);

        $claims = JWT::decode($cookie->getValue(), new Key(
            hash_hkdf('sha256', $encrypter->getKey() . '.mykey', 32, 'hypervel-passport-cookie'),
            'HS256'
        ));
        $this->assertSame(1, $claims->sub);
        $this->assertSame('token', $claims->csrf);
    }

    #[DataProvider('guards')]
    public function testCookieProviderFollowsTheSelectedGuard(?string $guard, string $defaultGuard, ?string $provider): void
    {
        config([
            'auth.guards.admins' => ['driver' => 'session', 'provider' => 'admins'],
            'auth.guards.providerless' => ['driver' => 'custom'],
        ]);
        $this->app->make(AuthManager::class)->shouldUse($defaultGuard);
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $factory = $this->makeFactory($this->app->make('config'), $encrypter);
        $cookie = $guard === null ? $factory->make(1, 'token') : $factory->make(1, 'token', $guard);
        $claims = JWT::decode($cookie->getValue(), new Key(
            hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie'),
            'HS256'
        ));

        $this->assertSame($provider, $claims->provider);
    }

    /**
     * Provide explicit and request-selected guards.
     */
    public static function guards(): array
    {
        return [
            ['admins', 'web', 'admins'],
            [null, 'admins', 'admins'],
            ['providerless', 'web', null],
            [null, 'providerless', null],
        ];
    }

    public function testCreationAndExpirationUseTheSameRequestCookieAttributes(): void
    {
        RequestContext::set(Request::create('https://example.com/organization'));
        config(['session.domain' => '.example.com']);
        StartSession::configureSessionCookieUsing(fn (Request $request, array $config): array => [
            ...$config,
            'path' => $request->getPathInfo(),
            'domain' => '',
            'secure' => true,
            'same_site' => 'none',
            'partitioned' => true,
        ]);
        Passport::bindCookiesUsing(fn (Request $request): string => $request->getPathInfo());
        $encrypter = new Encrypter(str_repeat('a', 32), 'aes-256-cbc');
        $factory = $this->makeFactory($this->app->make('config'), $encrypter);

        $cookie = $factory->make(1, 'token');
        $expired = $factory->forget();

        foreach ([$cookie, $expired] as $value) {
            $this->assertSame(Passport::cookie(), $value->getName());
            $this->assertSame('/organization', $value->getPath());
            $this->assertSame('', $value->getDomain());
            $this->assertTrue($value->isSecure());
            $this->assertTrue($value->isHttpOnly());
            $this->assertSame('none', $value->getSameSite());
            $this->assertTrue($value->isPartitioned());
        }

        $this->assertLessThan(time(), $expired->getExpiresTime());
        $claims = JWT::decode($cookie->getValue(), new Key(
            hash_hkdf('sha256', $encrypter->getKey(), 32, 'hypervel-passport-cookie'),
            'HS256'
        ));
        $this->assertSame('/organization', $claims->bind);
    }

    /**
     * Create the cookie factory with real key derivation and guard configuration.
     */
    protected function makeFactory(Repository $config, EncrypterContract $encrypter): ApiTokenCookieFactory
    {
        return new ApiTokenCookieFactory(
            $config,
            new ServerFactory($this->app, $config, $encrypter, $this->app->make(BearerRequestFactory::class)),
            $this->app->make(AuthManager::class),
            $this->app,
        );
    }
}
