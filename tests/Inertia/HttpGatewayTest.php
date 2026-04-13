<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Inertia\Ssr\SsrErrorType;
use Hypervel\Inertia\Ssr\SsrException;
use Hypervel\Inertia\Ssr\SsrRenderFailed;
use Hypervel\Support\Facades\Event;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class HttpGatewayTest extends TestCase
{
    protected HttpGateway $gateway;

    protected string $renderUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = app(HttpGateway::class);
        $this->renderUrl = $this->gateway->getProductionUrl('/render');
    }

    protected function tearDown(): void
    {
        $this->removeHotFile();

        parent::tearDown();
    }

    /**
     * Create a Guzzle client with a MockHandler queuing the given responses.
     */
    protected function mockSsrClient(array $responses): MockHandler
    {
        $mock = new MockHandler($responses);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);
        HttpGateway::useTestingClient($client);

        return $mock;
    }

    protected function createHotFile(string $url = 'http://localhost:5173'): void
    {
        file_put_contents(public_path('hot'), $url);
    }

    protected function removeHotFile(): void
    {
        $hotFile = public_path('hot');
        if (file_exists($hotFile)) {
            unlink($hotFile);
        }
    }

    public function testItReturnsNullWhenSsrIsDisabled(): void
    {
        config([
            'inertia.ssr.enabled' => false,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItReturnsNullWhenNoBundleFileIsDetected(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => null,
        ]);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItUsesTheConfiguredHttpUrlWhenTheBundleFileIsDetected(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(200, [], json_encode([
                'head' => ['<title>SSR Test</title>', '<style></style>'],
                'body' => '<div id="app">SSR Response</div>',
            ])),
        ]);

        $this->assertNotNull(
            $response = $this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT])
        );

        $this->assertEquals("<title>SSR Test</title>\n<style></style>", $response->head);
        $this->assertEquals('<div id="app">SSR Response</div>', $response->body);
    }

    public function testItUsesTheConfiguredHttpUrlWhenBundleFileDetectionIsDisabled(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
            'inertia.ssr.bundle' => null,
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(200, [], json_encode([
                'head' => ['<title>SSR Test</title>', '<style></style>'],
                'body' => '<div id="app">SSR Response</div>',
            ])),
        ]);

        $this->assertNotNull(
            $response = $this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT])
        );

        $this->assertEquals("<title>SSR Test</title>\n<style></style>", $response->head);
        $this->assertEquals('<div id="app">SSR Response</div>', $response->body);
    }

    public function testItReturnsNullWhenTheHttpRequestFails(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(500),
        ]);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItReturnsNullWhenInvalidJsonIsReturned(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(200, [], 'invalid json'),
        ]);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testHealthCheckTheSsrServer(): void
    {
        $this->mockSsrClient([
            new GuzzleResponse(200),
            new GuzzleResponse(500),
            new ConnectException('Connection refused', new GuzzleRequest('GET', '/')),
        ]);

        $this->assertTrue($this->gateway->isHealthy());
        $this->assertFalse($this->gateway->isHealthy());
        $this->assertFalse($this->gateway->isHealthy());
    }

    public function testItUsesViteHotUrlWhenRunningHot(): void
    {
        config(['inertia.ssr.enabled' => true]);

        $this->createHotFile('http://localhost:5173');

        $mock = $this->mockSsrClient([
            new GuzzleResponse(200, [], json_encode([
                'head' => ['<title>Hot SSR</title>'],
                'body' => '<div id="app">Hot Response</div>',
            ])),
        ]);

        $response = $this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertNotNull($response);
        $this->assertEquals('<title>Hot SSR</title>', $response->head);
        $this->assertEquals('<div id="app">Hot Response</div>', $response->body);

        // Verify the request was sent to the hot URL
        $lastRequest = $mock->getLastRequest();
        $this->assertStringContainsString('localhost:5173', (string) $lastRequest->getUri());
    }

    public function testItUsesViteHotUrlEvenWhenBundleFileExists(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->createHotFile('http://localhost:5173');

        $mock = $this->mockSsrClient([
            new GuzzleResponse(200, [], json_encode([
                'head' => ['<title>Hot SSR</title>'],
                'body' => '<div id="app">Hot Response</div>',
            ])),
        ]);

        $response = $this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertNotNull($response);
        $this->assertEquals('<title>Hot SSR</title>', $response->head);
        $this->assertEquals('<div id="app">Hot Response</div>', $response->body);

        // Verify hot URL was used, not production
        $lastRequest = $mock->getLastRequest();
        $this->assertStringContainsString('localhost:5173/__inertia_ssr', (string) $lastRequest->getUri());
    }

    public function testItReturnsNullWhenPathIsExcludedFromSsr(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->gateway->except(['admin/*']);

        $this->get('/admin/dashboard');

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItDispatchesWhenPathIsNotExcludedFromSsr(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(200, [], json_encode([
                'head' => ['<title>SSR Test</title>'],
                'body' => '<div id="app">SSR Response</div>',
            ])),
        ]);

        $this->gateway->except(['admin/*']);

        $this->get('/users');

        $this->assertNotNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItReturnsNullWhenFullUrlIsExcludedFromSsr(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->gateway->except(['http://localhost/admin/*']);

        $this->get('/admin/dashboard');

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testExceptAcceptsString(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->gateway->except('admin/*');

        $this->get('/admin/dashboard');

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testProductionUrlStripsTrailingSlash(): void
    {
        config(['inertia.ssr.url' => 'http://127.0.0.1:13714/']);

        $gateway = app(HttpGateway::class);

        $this->assertEquals('http://127.0.0.1:13714/render', $gateway->getProductionUrl('/render'));
    }

    public function testExceptCanBeCalledMultipleTimes(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->gateway->except('admin/*');
        $this->gateway->except(['nova/*', 'filament/*']);

        $this->get('/nova/resources');

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItDispatchesEventWhenSsrFails(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(500, [], json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
                'hint' => 'Wrap in lifecycle hook',
                'browserApi' => 'window',
            ])),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Event::assertDispatched(SsrRenderFailed::class, function (SsrRenderFailed $event) {
            return $event->error === 'window is not defined'
                && $event->type === SsrErrorType::BrowserApi
                && $event->hint === 'Wrap in lifecycle hook'
                && $event->browserApi === 'window'
                && $event->component() === 'Foo/Bar';
        });
    }

    public function testItHandlesConnectionErrorsGracefully(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->mockSsrClient([
            new ConnectException('Connection refused', new GuzzleRequest('GET', '/')),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Event::assertDispatched(SsrRenderFailed::class, function (SsrRenderFailed $event) {
            return $event->type === SsrErrorType::Connection
                && str_contains($event->error, 'Connection refused');
        });
    }

    public function testItThrowsExceptionWhenThrowOnErrorIsEnabled(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.throw_on_error' => true,
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(500, [], json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
                'hint' => 'Wrap in lifecycle hook',
                'browserApi' => 'window',
                'sourceLocation' => 'resources/js/Pages/Dashboard.vue:10:5',
            ])),
        ]);

        $this->expectException(SsrException::class);
        $this->expectExceptionMessage('SSR render failed for component [Foo/Bar]: window is not defined');

        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
    }

    public function testSsrExceptionContainsErrorDetails(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.throw_on_error' => true,
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(500, [], json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
                'hint' => 'Wrap in lifecycle hook',
                'browserApi' => 'window',
                'sourceLocation' => 'resources/js/Pages/Dashboard.vue:10:5',
            ])),
        ]);

        try {
            $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
            $this->fail('Expected SsrException was not thrown');
        } catch (SsrException $e) {
            $this->assertEquals('Foo/Bar', $e->component());
            $this->assertSame(SsrErrorType::BrowserApi, $e->type());
            $this->assertEquals('Wrap in lifecycle hook', $e->hint());
            $this->assertEquals('resources/js/Pages/Dashboard.vue:10:5', $e->sourceLocation());
            $this->assertStringContainsString('at resources/js/Pages/Dashboard.vue:10:5', $e->getMessage());
        }
    }

    public function testItThrowsExceptionOnConnectionErrorWhenThrowOnErrorIsEnabled(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.throw_on_error' => true,
        ]);

        $this->mockSsrClient([
            new ConnectException('Connection refused', new GuzzleRequest('GET', '/')),
        ]);

        $this->expectException(SsrException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
    }

    public function testItReturnsNullWhenDisabledWithBoolean(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->gateway->disable(true);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItReturnsNullWhenDisabledWithClosure(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->gateway->disable(fn () => true);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testDisableWhenTakesPrecedenceOverConfig(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->gateway->disable(false);

        $this->mockSsrClient([
            new GuzzleResponse(200, [], json_encode([
                'head' => ['<title>SSR Test</title>'],
                'body' => '<div id="app">SSR Response</div>',
            ])),
        ]);

        $this->assertNotNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItDoesNotThrowExceptionWhenThrowOnErrorIsDisabled(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.throw_on_error' => false,
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(500, [], json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
            ])),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
    }

    public function testCircuitBreakerSkipsSsrAfterFailure(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(500, [], json_encode([
                'error' => 'Server down',
                'type' => 'connection',
            ])),
            // Second response would succeed, but circuit breaker prevents it
            new GuzzleResponse(200, [], json_encode([
                'head' => ['<title>SSR</title>'],
                'body' => '<div>SSR</div>',
            ])),
        ]);

        // First dispatch fails — triggers circuit breaker
        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        // Second dispatch should be skipped (circuit breaker active)
        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
    }

    public function testCircuitBreakerResetsAfterFlushState(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(500, [], json_encode(['error' => 'Server down', 'type' => 'connection'])),
            new GuzzleResponse(200, [], json_encode(['head' => ['<title>SSR</title>'], 'body' => '<div>SSR</div>'])),
        ]);

        // First dispatch fails — triggers circuit breaker
        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);

        // Flush resets the circuit breaker
        HttpGateway::flushState();

        // Re-inject testing client since flushState clears it
        $this->mockSsrClient([
            new GuzzleResponse(200, [], json_encode(['head' => ['<title>SSR</title>'], 'body' => '<div>SSR</div>'])),
        ]);

        // Second dispatch should succeed — circuit breaker is reset
        $response = $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
        $this->assertNotNull($response);
        $this->assertSame('<div>SSR</div>', $response->body);
    }

    public function testItHandlesScalarJsonErrorResponseGracefully(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->mockSsrClient([
            new GuzzleResponse(500, [], '"Internal Server Error"'),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Event::assertDispatched(SsrRenderFailed::class, function (SsrRenderFailed $event) {
            return $event->error === 'Unknown SSR error';
        });
    }

    public function testSsrClientIsReusedAcrossDispatches(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        // Don't use useTestingClient — let the real ssrClient() create the client
        HttpGateway::flushState();

        $mock = new MockHandler([
            new GuzzleResponse(200, [], json_encode(['head' => [], 'body' => '<div>1</div>'])),
            new GuzzleResponse(200, [], json_encode(['head' => [], 'body' => '<div>2</div>'])),
        ]);

        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        HttpGateway::useTestingClient($client);

        $response1 = $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
        $response2 = $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);

        $this->assertNotNull($response1);
        $this->assertNotNull($response2);
        $this->assertSame('<div>1</div>', $response1->body);
        $this->assertSame('<div>2</div>', $response2->body);

        // Both dispatches used the same mock (2 responses consumed = same client)
        $this->assertSame(0, $mock->count());
    }

    public function testSsrClientDoesNotLeakCookiesBetweenRequests(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $history = [];
        $mock = new MockHandler([
            new GuzzleResponse(200, ['Set-Cookie' => 'session=abc123'], json_encode(['head' => [], 'body' => '<div>1</div>'])),
            new GuzzleResponse(200, [], json_encode(['head' => [], 'body' => '<div>2</div>'])),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $stack,
            'http_errors' => false,
            'cookies' => false,
        ]);

        HttpGateway::useTestingClient($client);

        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);

        // Second request should NOT have a Cookie header
        $this->assertCount(2, $history);
        $this->assertFalse($history[1]['request']->hasHeader('Cookie'));
    }

    public function testSsrClientUsesConfiguredTimeouts(): void
    {
        config([
            'inertia.ssr.connect_timeout' => 3,
            'inertia.ssr.timeout' => 10,
        ]);

        // Flush to force client rebuild with new config
        HttpGateway::flushState();

        // Access the client via reflection to check its config
        $gateway = new HttpGateway;
        $method = new ReflectionMethod($gateway, 'ssrClient');
        $client = $method->invoke($gateway);

        $this->assertSame(3, $client->getConfig('connect_timeout'));
        $this->assertSame(10, $client->getConfig('timeout'));
        $this->assertFalse($client->getConfig('cookies'));
        $this->assertFalse($client->getConfig('http_errors'));

        // Verify the client is memoized (same instance on second call)
        $this->assertSame($client, $method->invoke($gateway));
    }
}
