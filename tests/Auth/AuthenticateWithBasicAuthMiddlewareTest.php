<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\Middleware\AuthenticateWithBasicAuth;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\SupportsBasicAuth;
use Hypervel\Http\Request;
use Hypervel\Pipeline\Pipeline;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithBasicAuthMiddlewareTest extends TestCase
{
    use VerifiesDoubles;

    public function testUsingGeneratesCorrectMiddlewareString(): void
    {
        $this->assertSame(
            AuthenticateWithBasicAuth::class . ':',
            AuthenticateWithBasicAuth::using()
        );

        $this->assertSame(
            AuthenticateWithBasicAuth::class . ':api',
            AuthenticateWithBasicAuth::using('api')
        );

        $this->assertSame(
            AuthenticateWithBasicAuth::class . ':api,username',
            AuthenticateWithBasicAuth::using('api', 'username')
        );
    }

    public function testItCallsBasicWithDefaultField(): void
    {
        $guard = Double::for(Guard::class, SupportsBasicAuth::class)->strict();
        $guard->expects('basic')->with('email')->returns(null);

        $authFactory = Double::for(AuthFactory::class)->strict();
        $authFactory->expects('guard')->with(null)->returns($guard);

        $middleware = new AuthenticateWithBasicAuth($authFactory);
        $request = Request::create('/', 'GET');
        $expectedResponse = new Response('ok');

        $result = $middleware->handle($request, fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $result);
    }

    public function testItCallsBasicWithCustomField(): void
    {
        $guard = Double::for(Guard::class, SupportsBasicAuth::class)->strict();
        $guard->expects('basic')->with('username')->returns(null);

        $authFactory = Double::for(AuthFactory::class)->strict();
        $authFactory->expects('guard')->with('api')->returns($guard);

        $middleware = new AuthenticateWithBasicAuth($authFactory);
        $request = Request::create('/', 'GET');
        $expectedResponse = new Response('ok');

        $result = $middleware->handle($request, fn () => $expectedResponse, 'api', 'username');

        $this->assertSame($expectedResponse, $result);
    }

    public function testItUsesSpecifiedGuard(): void
    {
        $guard = Double::for(Guard::class, SupportsBasicAuth::class)->strict();
        $guard->expects('basic')->with('email')->returns(null);

        $authFactory = Double::for(AuthFactory::class)->strict();
        $authFactory->expects('guard')->with('web')->returns($guard);

        $middleware = new AuthenticateWithBasicAuth($authFactory);
        $request = Request::create('/', 'GET');
        $expectedResponse = new Response('ok');

        $result = $middleware->handle($request, fn () => $expectedResponse, 'web');

        $this->assertSame($expectedResponse, $result);
    }

    public function testFieldOnlyDefinitionUsesDefaultGuard(): void
    {
        $container = new Container;
        $container->instance('config', new Repository([
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => ['web' => ['driver' => 'basic']],
            ],
        ]));

        $guard = Double::for(Guard::class, SupportsBasicAuth::class)->strict();
        $guard->expects('basic')->with('username')->returns(null);

        $auth = new AuthManager($container);
        $auth->extend('basic', fn () => $guard);
        $container->instance(AuthFactory::class, $auth);

        $expectedResponse = new Response('ok');

        $response = (new Pipeline($container))
            ->send(Request::create('/', 'GET'))
            ->through([AuthenticateWithBasicAuth::using(field: 'username')])
            ->then(fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $response);
    }
}
