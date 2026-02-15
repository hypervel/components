<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hyperf\HttpMessage\Base\Response;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\Http\ServerResponse;
use Hypervel\Foundation\Testing\Stubs\FakeMiddleware;
use Hypervel\Router\RouteFileCollector;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @internal
 * @coversNothing
 */
class MakesHttpRequestsTest extends TestCase
{
    use RunTestsInCoroutine;

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

    public function testFollowingRedirects()
    {
        $this->app->make(RouteFileCollector::class)
            ->addRouteFile(dirname(__DIR__, 2) . '/fixtures/routes/test-api.php');

        $response = (new ServerResponse())
            ->withStatus(301)
            ->withHeader('Location', 'http://localhost/foo');

        $this->followRedirects(new TestResponse($response))
            ->assertSuccessful()
            ->assertContent('foo');
    }

    public function testGetNotFound()
    {
        $this->get('/foo')
            ->assertNotFound();
    }

    public function testGetFoundRoute()
    {
        $this->app->make(RouteFileCollector::class)
            ->addRouteFile(dirname(__DIR__, 2) . '/fixtures/routes/test-api.php');

        $this->get('/foo')
            ->assertSuccessFul()
            ->assertContent('foo');
    }

    public function testGetFoundRouteWithTrailingSlash()
    {
        $this->app->make(RouteFileCollector::class)
            ->addRouteFile(dirname(__DIR__, 2) . '/fixtures/routes/test-api.php');

        $this->get('/foo/')
            ->assertSuccessFul()
            ->assertContent('foo');
    }

    public function testGetServerParams()
    {
        $this->app->make(RouteFileCollector::class)
            ->addRouteFile(dirname(__DIR__, 2) . '/fixtures/routes/test-api.php');

        $this->get('/server-params?foo=bar')
            ->assertSuccessful()
            ->assertJson([
                'request_method' => 'GET',
                'request_uri' => 'server-params',
                'query_string' => 'foo=bar',
            ]);
    }

    public function testGetStreamedContent()
    {
        $this->app->make(RouteFileCollector::class)
            ->addRouteFile(dirname(__DIR__, 2) . '/fixtures/routes/test-api.php');

        $this->get('/stream')
            ->assertSuccessFul()
            ->assertStreamedContent('stream');
    }

    public function testWithHeaders()
    {
        $this->app->make(RouteFileCollector::class)
            ->addRouteFile(dirname(__DIR__, 2) . '/fixtures/routes/test-api.php');

        $this->withHeaders([
            'X-Header' => 'Value',
        ])->get('/headers')
            ->assertSuccessFul()
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
}

class MyMiddleware
{
    public function handle($request, $next)
    {
        return $next($request . 'WithMiddleware');
    }
}
