<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Middleware\EnsureEmailIsVerified;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureEmailIsVerifiedTest extends TestCase
{
    use VerifiesDoubles;

    public function testItCanGenerateDefinitionViaStaticMethod(): void
    {
        $signature = EnsureEmailIsVerified::redirectTo('route.name');
        $this->assertSame('Hypervel\Auth\Middleware\EnsureEmailIsVerified:route.name', $signature);
    }

    public function testVerifiedUserPassesThrough(): void
    {
        $user = Double::for(Authenticatable::class, MustVerifyEmail::class);
        $user->allows('hasVerifiedEmail')->returns(true);

        $request = Double::for(Request::class, override: true);
        $request->allows('user')->returns($user);

        $expectedResponse = new Response('ok');
        $middleware = new EnsureEmailIsVerified;
        $result = $middleware->handle($request->instance(), fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $result);
    }

    public function testUserThatDoesNotImplementMustVerifyEmailPassesThrough(): void
    {
        // User implements Authenticatable but does not implement MustVerifyEmail.
        $user = Double::for(Authenticatable::class);

        $request = Double::for(Request::class, override: true);
        $request->allows('user')->returns($user);

        $expectedResponse = new Response('ok');
        $middleware = new EnsureEmailIsVerified;
        $result = $middleware->handle($request->instance(), fn () => $expectedResponse);

        $this->assertSame($expectedResponse, $result);
    }

    public function testGuestRequestReturnsJsonWhenExpectsJson(): void
    {
        $request = Double::for(Request::class, override: true);
        $request->allows('user')->returns(null);
        $request->allows('expectsJson')->returns(true);

        $this->expectException(HttpException::class);

        $middleware = new EnsureEmailIsVerified;
        $middleware->handle($request->instance(), fn () => new Response('should not reach'));
    }

    public function testUnverifiedUserReturnsJsonWhenExpectsJson(): void
    {
        $user = Double::for(Authenticatable::class, MustVerifyEmail::class);
        $user->allows('hasVerifiedEmail')->returns(false);

        $request = Double::for(Request::class, override: true);
        $request->allows('user')->returns($user);
        $request->allows('expectsJson')->returns(true);

        $this->expectException(HttpException::class);

        $middleware = new EnsureEmailIsVerified;
        $middleware->handle($request->instance(), fn () => new Response('should not reach'));
    }

    public function testUnverifiedUserRedirectsWhenNotJson(): void
    {
        // Register a named route so URL::route() can resolve it
        $this->app->make('router')->get('/email/verify', fn () => 'verify')->name('verify.email');

        $user = Double::for(Authenticatable::class, MustVerifyEmail::class);
        $user->allows('hasVerifiedEmail')->returns(false);

        $request = Double::for(Request::class, override: true);
        $request->allows('user')->returns($user);
        $request->allows('expectsJson')->returns(false);

        $middleware = new EnsureEmailIsVerified;
        $result = $middleware->handle($request->instance(), fn () => new Response('should not reach'), 'verify.email');

        $this->assertSame(302, $result->getStatusCode());
    }

    public function testGuestRequestRedirectsWhenNotJson(): void
    {
        $this->app->make('router')->get('/email/verify', fn () => 'verify')->name('verify.email');

        $request = Double::for(Request::class, override: true);
        $request->allows('user')->returns(null);
        $request->allows('expectsJson')->returns(false);

        $middleware = new EnsureEmailIsVerified;
        $result = $middleware->handle($request->instance(), fn () => new Response('should not reach'), 'verify.email');

        $this->assertSame(302, $result->getStatusCode());
    }
}
