<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Inertia\Ssr\SsrErrorType;
use Hypervel\Inertia\Ssr\SsrException;
use Hypervel\Inertia\Ssr\SsrRenderFailed;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;

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

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->removeHotFile();

        parent::tearDown();
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
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

        Http::fake([
            $this->renderUrl => Http::response(null, 500),
        ]);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    public function testItReturnsNullWhenInvalidJsonIsReturned(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        Http::fake([
            $this->renderUrl => Http::response('invalid json'),
        ]);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    /**
     * Create a new connection exception for use during stubbing.
     *
     * This is copied over from Laravel's Http::failedConnection() helper
     * method, which is only available in Laravel 11.32.0 and later.
     */
    private static function rejectionForFailedConnection(): PromiseInterface
    {
        return Create::rejectionFor(
            new ConnectException('Connection refused', new Request('GET', '/'))
        );
    }

    public function testHealthCheckTheSsrServer(): void
    {
        Http::fake([
            $this->gateway->getProductionUrl('/health') => Http::sequence()
                ->push(status: 200)
                ->push(status: 500)
                ->pushResponse(self::rejectionForFailedConnection()),
        ]);

        $this->assertTrue($this->gateway->isHealthy());
        $this->assertFalse($this->gateway->isHealthy());
        $this->assertFalse($this->gateway->isHealthy());
    }

    public function testItUsesViteHotUrlWhenRunningHot(): void
    {
        config(['inertia.ssr.enabled' => true]);

        $this->createHotFile('http://localhost:5173');

        Http::fake([
            'http://localhost:5173/__inertia_ssr' => Http::response(json_encode([
                'head' => ['<title>Hot SSR</title>'],
                'body' => '<div id="app">Hot Response</div>',
            ])),
        ]);

        $response = $this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertNotNull($response);
        $this->assertEquals('<title>Hot SSR</title>', $response->head);
        $this->assertEquals('<div id="app">Hot Response</div>', $response->body);
    }

    public function testItUsesViteHotUrlEvenWhenBundleFileExists(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->createHotFile('http://localhost:5173');

        Http::fake([
            'http://localhost:5173/__inertia_ssr' => Http::response(json_encode([
                'head' => ['<title>Hot SSR</title>'],
                'body' => '<div id="app">Hot Response</div>',
            ])),
            $this->renderUrl => Http::response(json_encode([
                'head' => ['<title>Production SSR</title>'],
                'body' => '<div id="app">Production Response</div>',
            ])),
        ]);

        $response = $this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertNotNull($response);
        $this->assertEquals('<title>Hot SSR</title>', $response->head);
        $this->assertEquals('<div id="app">Hot Response</div>', $response->body);
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
                'hint' => 'Wrap in lifecycle hook',
                'browserApi' => 'window',
            ]), 500),
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

        Http::fake([
            $this->renderUrl => self::rejectionForFailedConnection(),
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
                'hint' => 'Wrap in lifecycle hook',
                'browserApi' => 'window',
                'sourceLocation' => 'resources/js/Pages/Dashboard.vue:10:5',
            ]), 500),
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
                'hint' => 'Wrap in lifecycle hook',
                'browserApi' => 'window',
                'sourceLocation' => 'resources/js/Pages/Dashboard.vue:10:5',
            ]), 500),
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

        Http::fake([
            $this->renderUrl => self::rejectionForFailedConnection(),
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
            ]), 500),
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

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => 'Server down',
                'type' => 'connection',
            ]), 500),
        ]);

        // First dispatch fails — triggers circuit breaker
        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        // Second dispatch should be skipped (circuit breaker active)
        // Even with a successful fake, gateway won't attempt the request
        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'head' => ['<title>SSR</title>'],
                'body' => '<div>SSR</div>',
            ])),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
    }

    public function testCircuitBreakerResetsAfterFlushState(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        Http::fake([
            $this->renderUrl => Http::sequence()
                ->push(json_encode(['error' => 'Server down', 'type' => 'connection']), 500)
                ->push(json_encode(['head' => ['<title>SSR</title>'], 'body' => '<div>SSR</div>'])),
        ]);

        // First dispatch fails — triggers circuit breaker
        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);

        // Flush resets the circuit breaker
        HttpGateway::flushState();

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

        Http::fake([
            $this->renderUrl => Http::response('"Internal Server Error"', 500),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Event::assertDispatched(SsrRenderFailed::class, function (SsrRenderFailed $event) {
            return $event->error === 'Unknown SSR error';
        });
    }
}
