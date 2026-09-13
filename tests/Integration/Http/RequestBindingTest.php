<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Closure;
use Hypervel\Auth\GenericUser;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

class RequestBindingTest extends TestCase
{
    public function testFallbackRequestUsesTheConfiguredApplicationUrl(): void
    {
        config(['app.url' => 'https://example.test/base']);
        RequestContext::forget();

        $request = $this->app->make('request');

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('https://example.test/base', $request->getUri());
    }

    public function testFallbackRequestIsFreshForEveryResolution(): void
    {
        RequestContext::forget();

        $firstRequest = $this->app->make('request');
        $firstRequest->merge(['name' => 'John']);
        $secondRequest = $this->app->make('request');

        $this->assertNotSame($firstRequest, $secondRequest);
        $this->assertSame('John', $firstRequest->input('name'));
        $this->assertNull($secondRequest->input('name'));
    }

    public function testFallbackRequestUsesLocalhostWhenTheApplicationUrlIsMissing(): void
    {
        $app = config()->array('app');
        unset($app['url']);
        config(['app' => $app]);
        RequestContext::forget();

        $request = $this->app->make('request');

        $this->assertSame('http://localhost/', $request->getUri());
    }

    public function testContextualRequestIsReturnedForEveryResolution(): void
    {
        $request = RequestContext::set(Request::create('/?name=John'));

        $this->assertSame($request, $this->app->make('request'));
        $this->assertSame($request, $this->app->make('request'));
        $this->assertSame('John', request('name'));
    }

    public function testMiddlewareResolvesUserAndPreservesRequestOverride(): void
    {
        $user = new GenericUser(['id' => 1]);
        $otherUser = new GenericUser(['id' => 2]);

        Route::aliasMiddleware('resolve-user', function (Request $request, Closure $next) use ($user, $otherUser): Response {
            $this->assertSame($user, $request->user());

            $request->setUserResolver(fn (): GenericUser => $otherUser);

            $this->assertSame($request, $this->app->make('request'));
            $this->assertSame($otherUser, $request->user());

            return $next($request);
        });

        Route::get('/', fn (): string => 'ok')->middleware('resolve-user');

        $this->actingAs($user)->get('/')->assertOk()->assertContent('ok');
    }
}
