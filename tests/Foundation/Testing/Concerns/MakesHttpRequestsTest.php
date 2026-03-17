<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Hypervel\Foundation\Testing\Stubs\FakeMiddleware;
use Hypervel\Http\Response;
use Hypervel\Routing\Router;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\LoggedExceptionCollection;
use Hypervel\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @internal
 * @coversNothing
 */
class MakesHttpRequestsTest extends TestCase
{
    public function testFromSetsHeaderAndSession()
    {
        $this->from('previous/url');

        $this->assertSame('previous/url', $this->defaultHeaders['referer']);
        $this->assertSame('previous/url', $this->app['session']->previousUrl());
    }

    public function testFromRouteSetsHeaderAndSession()
    {
        $router = $this->app->make(Registrar::class);

        $router->get('previous/url', fn () => 'ok')->name('previous-url');

        $this->fromRoute('previous-url');

        $this->assertSame('http://localhost/previous/url', $this->defaultHeaders['referer']);
        $this->assertSame('http://localhost/previous/url', $this->app['session']->previousUrl());
    }

    public function testFromRemoveHeader()
    {
        $this->withHeader('name', 'Milwad')->from('previous/url');

        $this->assertEquals('Milwad', $this->defaultHeaders['name']);

        $this->withoutHeader('name')->from('previous/url');

        $this->assertArrayNotHasKey('name', $this->defaultHeaders);
    }

    public function testFromRemoveHeaders()
    {
        $this->withHeaders([
            'name' => 'Milwad',
            'foo' => 'bar',
        ])->from('previous/url');

        $this->assertEquals('Milwad', $this->defaultHeaders['name']);
        $this->assertEquals('bar', $this->defaultHeaders['foo']);

        $this->withoutHeaders(['name', 'foo'])->from('previous/url');

        $this->assertArrayNotHasKey('name', $this->defaultHeaders);
        $this->assertArrayNotHasKey('foo', $this->defaultHeaders);
    }

    public function testWithTokenSetsAuthorizationHeader()
    {
        $this->withToken('foobar');
        $this->assertSame('Bearer foobar', $this->defaultHeaders['Authorization']);

        $this->withToken('foobar', 'Basic');
        $this->assertSame('Basic foobar', $this->defaultHeaders['Authorization']);
    }

    public function testWithBasicAuthSetsAuthorizationHeader()
    {
        $callback = function ($username, $password) {
            return base64_encode("{$username}:{$password}");
        };

        $username = 'foo';
        $password = 'bar';

        $this->withBasicAuth($username, $password);
        $this->assertSame('Basic ' . $callback($username, $password), $this->defaultHeaders['Authorization']);

        $password = 'buzz';

        $this->withBasicAuth($username, $password);
        $this->assertSame('Basic ' . $callback($username, $password), $this->defaultHeaders['Authorization']);
    }

    public function testWithoutTokenRemovesAuthorizationHeader()
    {
        $this->withToken('foobar');
        $this->assertSame('Bearer foobar', $this->defaultHeaders['Authorization']);

        $this->withoutToken();
        $this->assertArrayNotHasKey('Authorization', $this->defaultHeaders);
    }

    public function testWithoutAndWithMiddleware()
    {
        $this->assertFalse($this->app->has('middleware.disable'));

        $this->withoutMiddleware();
        $this->assertTrue($this->app->has('middleware.disable'));
        $this->assertTrue($this->app->make('middleware.disable'));

        $this->withMiddleware();
        $this->assertFalse($this->app->has('middleware.disable'));
    }

    public function testWithoutAndWithMiddlewareWithParameter()
    {
        $next = function ($request) {
            return $request;
        };

        $this->assertFalse($this->app->bound(MyMiddleware::class));
        $this->assertSame(
            'fooWithMiddleware',
            $this->app->make(MyMiddleware::class)->handle('foo', $next)
        );

        $this->withoutMiddleware(MyMiddleware::class);
        $this->assertTrue($this->app->bound(MyMiddleware::class));
        $this->assertInstanceOf(FakeMiddleware::class, $this->app->make(MyMiddleware::class));

        $this->withMiddleware(MyMiddleware::class);
        $this->assertFalse($this->app->bound(MyMiddleware::class));
        $this->assertSame(
            'fooWithMiddleware',
            $this->app->make(MyMiddleware::class)->handle('foo', $next)
        );
    }

    public function testWithCookieSetCookie()
    {
        $this->withCookie('foo', 'bar');

        $this->assertCount(1, $this->defaultCookies);
        $this->assertSame('bar', $this->defaultCookies['foo']);
    }

    public function testWithCookiesSetsCookiesAndOverwritesPreviousValues()
    {
        $this->withCookie('foo', 'bar');
        $this->withCookies([
            'foo' => 'baz',
            'new-cookie' => 'new-value',
        ]);

        $this->assertCount(2, $this->defaultCookies);
        $this->assertSame('baz', $this->defaultCookies['foo']);
        $this->assertSame('new-value', $this->defaultCookies['new-cookie']);
    }

    public function testWithUnencryptedCookieSetCookie()
    {
        $this->withUnencryptedCookie('foo', 'bar');

        $this->assertCount(1, $this->unencryptedCookies);
        $this->assertSame('bar', $this->unencryptedCookies['foo']);
    }

    public function testWithUnencryptedCookiesSetsCookiesAndOverwritesPreviousValues()
    {
        $this->withUnencryptedCookie('foo', 'bar');
        $this->withUnencryptedCookies([
            'foo' => 'baz',
            'new-cookie' => 'new-value',
        ]);

        $this->assertCount(2, $this->unencryptedCookies);
        $this->assertSame('baz', $this->unencryptedCookies['foo']);
        $this->assertSame('new-value', $this->unencryptedCookies['new-cookie']);
    }

    public function testWithoutAndWithCredentials()
    {
        $this->encryptCookies = false;

        $this->assertSame([], $this->prepareCookiesForJsonRequest());

        $this->withCredentials();
        $this->defaultCookies = ['foo' => 'bar'];
        $this->assertSame(['foo' => 'bar'], $this->prepareCookiesForJsonRequest());
    }

    public function testCookieHelperRespectsConfiguredSecureDefault()
    {
        config(['session.secure' => true]);

        $cookie = cookie('foo', 'bar');

        $this->assertTrue($cookie->isSecure());
    }

    public function testFollowingRedirects()
    {
        $router = $this->app->make(Router::class);
        $router->get('/foo', fn () => 'foo');

        $response = new Response('', 301, ['Location' => '/foo']);

        $this->followRedirects(TestResponse::fromBaseResponse($response))
            ->assertSuccessful()
            ->assertSee('foo');
    }

    public function testGetNotFound()
    {
        $this->get('/foo')
            ->assertNotFound();
    }

    public function testGetFoundRoute()
    {
        $this->app->make(Router::class)->get('/foo', fn () => 'foo');

        $this->get('/foo')
            ->assertSuccessful()
            ->assertSee('foo');
    }

    public function testGetFoundRouteWithTrailingSlash()
    {
        $this->app->make(Router::class)->get('/foo', fn () => 'foo');

        $this->get('/foo/')
            ->assertSuccessful()
            ->assertSee('foo');
    }

    public function testWithHeaders()
    {
        $this->app->make(Router::class)->get('/headers', function (\Hypervel\Http\Request $request) {
            return new Response(
                'hello',
                200,
                ['X-Header' => $request->header('X-Header')]
            );
        });

        $this->withHeaders([
            'X-Header' => 'Value',
        ])->get('/headers')
            ->assertSuccessful()
            ->assertHeader('X-Header', 'Value');
    }

    public function testAssertSessionHasErrors()
    {
        $this->app->instance('session.store', $store = new Store('test-session', new ArraySessionHandler(1)));

        $store->put('errors', $errorBag = new ViewErrorBag());

        $errorBag->put('default', new MessageBag([
            'foo' => [
                'foo is required',
            ],
        ]));

        $response = TestResponse::fromBaseResponse(new Response());

        $response->assertSessionHasErrors(['foo']);
    }

    public function testAssertJsonSerializedSessionHasErrors()
    {
        $this->app->instance('session.store', $store = new Store('test-session', new ArraySessionHandler(1)));

        $store->put('errors', $errorBag = new ViewErrorBag());

        $errorBag->put('default', new MessageBag([
            'foo' => [
                'foo is required',
            ],
        ]));

        $store->save(); // Required to serialize error bag to JSON

        $response = TestResponse::fromBaseResponse(new Response());

        $response->assertSessionHasErrors(['foo']);
    }

    public function testAssertSessionDoesntHaveErrors()
    {
        $this->expectException(AssertionFailedError::class);

        $this->app->instance('session.store', $store = new Store('test-session', new ArraySessionHandler(1)));

        $store->put('errors', $errorBag = new ViewErrorBag());

        $errorBag->put('default', new MessageBag([
            'foo' => [
                'foo is required',
            ],
        ]));

        $response = TestResponse::fromBaseResponse(new Response());

        $response->assertSessionDoesntHaveErrors(['foo']);
    }

    public function testAssertSessionHasNoErrors()
    {
        $this->app->instance('session.store', $store = new Store('test-session', new ArraySessionHandler(1)));

        $store->put('errors', $errorBag = new ViewErrorBag());

        $errorBag->put('default', new MessageBag([
            'foo' => [
                'foo is required',
            ],
        ]));

        $errorBag->put('some-other-bag', new MessageBag([
            'bar' => [
                'bar is required',
            ],
        ]));

        $response = TestResponse::fromBaseResponse(new Response());

        try {
            $response->assertSessionHasNoErrors();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('foo is required', $e->getMessage());
            $this->assertStringContainsString('bar is required', $e->getMessage());
        }
    }

    public function testAssertSessionHas()
    {
        $this->app->instance('session.store', $store = new Store('test-session', new ArraySessionHandler(1)));

        $store->put('foo', 'value');
        $store->put('bar', 'value');

        $response = TestResponse::fromBaseResponse(new Response());

        $response->assertSessionHas('foo');
        $response->assertSessionHas('bar');
        $response->assertSessionHas(['foo', 'bar']);
    }

    public function testAssertSessionMissing()
    {
        $this->expectException(AssertionFailedError::class);

        $this->app->instance('session.store', $store = new Store('test-session', new ArraySessionHandler(1)));

        $store->put('foo', 'value');

        $response = TestResponse::fromBaseResponse(new Response());
        $response->assertSessionMissing('foo');
    }

    public function testAssertSessionHasInput()
    {
        $this->app->instance('session.store', $store = new Store('test-session', new ArraySessionHandler(1)));

        $store->put('_old_input', [
            'foo' => 'value',
            'bar' => 'value',
        ]);

        $response = TestResponse::fromBaseResponse(new Response());

        $response->assertSessionHasInput('foo');
        $response->assertSessionHasInput('foo', 'value');
        $response->assertSessionHasInput('bar');
        $response->assertSessionHasInput('bar', 'value');
        $response->assertSessionHasInput(['foo', 'bar']);
        $response->assertSessionHasInput('foo', function ($value) {
            return $value === 'value';
        });
    }

    public function testFollowingRedirectsTerminatesInExpectedOrder()
    {
        $router = $this->app->make(Registrar::class);

        $callOrder = [];
        TerminatingMiddleware::$callback = function ($request) use (&$callOrder) {
            $callOrder[] = $request->path();
        };

        $router->get('from', function () {
            return new \Hypervel\Http\RedirectResponse('http://localhost/to');
        })->middleware(TerminatingMiddleware::class);

        $router->get('to', function () {
            return 'OK';
        })->middleware(TerminatingMiddleware::class);

        $this->followingRedirects()->get('from');

        $this->assertEquals(['from', 'to'], $callOrder);
    }

    public function testWithPrecognition()
    {
        $this->withPrecognition();
        $this->assertSame('true', $this->defaultHeaders['Precognition']);

        $this->app->make(Registrar::class)
            ->get('test-route', fn () => 'ok')->middleware(HandlePrecognitiveRequests::class);
        $this->get('test-route')
            ->assertStatus(204)
            ->assertHeader('Precognition', 'true')
            ->assertHeader('Precognition-Success', 'true');
    }

    public function testCreateTestResponsePassesLoggedExceptionCollection()
    {
        $this->app->make(Registrar::class)
            ->get('test-route', fn () => 'ok');

        $response = $this->get('test-route');

        $this->assertInstanceOf(LoggedExceptionCollection::class, $response->exceptions);
    }

    public function testCreateTestResponseUsesContainerBoundExceptionCollection()
    {
        $collection = new LoggedExceptionCollection();
        $this->app->instance(LoggedExceptionCollection::class, $collection);

        $this->app->make(Registrar::class)
            ->get('test-route', fn () => 'ok');

        $response = $this->get('test-route');

        $this->assertSame($collection, $response->exceptions);
    }
}

class MyMiddleware
{
    public function handle($request, $next)
    {
        return $next($request . 'WithMiddleware');
    }
}

class TerminatingMiddleware
{
    public static $callback;

    public function handle($request, $next)
    {
        return $next($request);
    }

    public function terminate($request, $response)
    {
        call_user_func(static::$callback, $request, $response);
    }
}
