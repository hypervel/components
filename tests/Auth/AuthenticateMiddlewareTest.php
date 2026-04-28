<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\AuthenticateWithBasicAuth;
use Hypervel\Auth\RequestGuard;
use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMiddlewareTest extends TestCase
{
    protected AuthManager $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::setInstance(new Container);

        $this->auth = new AuthManager($container);

        $container->singleton('config', function () {
            return $this->createConfig();
        });

        $container->singleton('request', fn () => m::mock(Request::class));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testItCanGenerateDefinitionViaStaticMethod()
    {
        $signature = Authenticate::using('foo');
        $this->assertSame('Hypervel\Auth\Middleware\Authenticate:foo', $signature);

        $signature = Authenticate::using('foo', 'bar');
        $this->assertSame('Hypervel\Auth\Middleware\Authenticate:foo,bar', $signature);

        $signature = Authenticate::using('foo', 'bar', 'baz');
        $this->assertSame('Hypervel\Auth\Middleware\Authenticate:foo,bar,baz', $signature);
    }

    public function testItCanGenerateDefinitionViaStaticMethodForBasic()
    {
        $signature = AuthenticateWithBasicAuth::using('guard');
        $this->assertSame('Hypervel\Auth\Middleware\AuthenticateWithBasicAuth:guard', $signature);

        $signature = AuthenticateWithBasicAuth::using('guard', 'field');
        $this->assertSame('Hypervel\Auth\Middleware\AuthenticateWithBasicAuth:guard,field', $signature);

        $signature = AuthenticateWithBasicAuth::using(field: 'field');
        $this->assertSame('Hypervel\Auth\Middleware\AuthenticateWithBasicAuth:,field', $signature);
    }

    public function testDefaultUnauthenticatedThrows()
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthenticated.');

        $this->registerAuthDriver('default', false);

        $this->authenticate();
    }

    public function testDefaultUnauthenticatedThrowsWithGuards()
    {
        try {
            $this->registerAuthDriver('default', false);

            $this->authenticate('default');
        } catch (AuthenticationException $e) {
            $this->assertContains('default', $e->guards());

            return;
        }

        $this->fail();
    }

    public function testDefaultAuthenticatedKeepsDefaultDriver()
    {
        $driver = $this->registerAuthDriver('default', true);

        $this->authenticate();

        $this->assertSame($driver, $this->auth->guard());
    }

    public function testSecondaryAuthenticatedUpdatesDefaultDriver()
    {
        $this->registerAuthDriver('default', false);

        $secondary = $this->registerAuthDriver('secondary', true);

        $this->authenticate('secondary');

        $this->assertSame($secondary, $this->auth->guard());
    }

    public function testMultipleDriversUnauthenticatedThrows()
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthenticated.');

        $this->registerAuthDriver('default', false);

        $this->registerAuthDriver('secondary', false);

        $this->authenticate('default', 'secondary');
    }

    public function testMultipleDriversUnauthenticatedThrowsWithGuards()
    {
        $expectedGuards = ['default', 'secondary'];

        try {
            $this->registerAuthDriver('default', false);

            $this->registerAuthDriver('secondary', false);

            $this->authenticate(...$expectedGuards);
        } catch (AuthenticationException $e) {
            $this->assertEquals($expectedGuards, $e->guards());

            return;
        }

        $this->fail();
    }

    public function testMultipleDriversAuthenticatedUpdatesDefault()
    {
        $this->registerAuthDriver('default', false);

        $secondary = $this->registerAuthDriver('secondary', true);

        $this->authenticate('default', 'secondary');

        $this->assertSame($secondary, $this->auth->guard());
    }

    public function testCustomDriverClosureBoundObjectIsAuthManager()
    {
        $boundTo = null;

        $this->auth->extend(__CLASS__, function () use (&$boundTo) {
            $boundTo = $this;

            return m::mock(\Hypervel\Contracts\Auth\Guard::class);
        });

        $this->auth->guard(__CLASS__);

        $this->assertSame($this->auth, $boundTo);
    }

    /**
     * Create a new config repository instance.
     */
    protected function createConfig(): Config
    {
        return new Config([
            'auth' => [
                'defaults' => ['guard' => 'default'],
                'guards' => [
                    'default' => ['driver' => 'default'],
                    'secondary' => ['driver' => 'secondary'],
                    __CLASS__ => ['driver' => __CLASS__],
                ],
            ],
        ]);
    }

    /**
     * Create and register a new auth driver with the auth manager.
     */
    protected function registerAuthDriver(string $name, bool $authenticated): RequestGuard
    {
        $driver = $this->createAuthDriver($name, $authenticated);

        $this->auth->extend($name, function () use ($driver) {
            return $driver;
        });

        return $driver;
    }

    /**
     * Create a new auth driver.
     */
    protected function createAuthDriver(string $name, bool $authenticated): RequestGuard
    {
        return new RequestGuard($name, function () use ($authenticated) {
            return $authenticated ? m::mock(Authenticatable::class) : null;
        }, Container::getInstance(), m::mock(EloquentUserProvider::class));
    }

    /**
     * Call the authenticate middleware with the given guards.
     *
     * @throws AuthenticationException
     */
    protected function authenticate(string ...$guards): void
    {
        $request = m::mock(Request::class);

        $request->shouldReceive('expectsJson')->andReturn(false);

        $nextParam = null;
        $response = new Response;

        $next = function ($param) use (&$nextParam, $response) {
            $nextParam = $param;

            return $response;
        };

        (new Authenticate($this->auth))->handle($request, $next, ...$guards);

        $this->assertSame($request, $nextParam);
    }
}
