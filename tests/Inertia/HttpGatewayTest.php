<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\Request as ClientRequest;
use Hypervel\Http\Client\Response as ClientResponse;
use Hypervel\Http\Client\StrayRequestException;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Inertia\Ssr\SsrErrorType;
use Hypervel\Inertia\Ssr\SsrException;
use Hypervel\Inertia\Ssr\SsrRenderFailed;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Vite;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

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

    #[DataProvider('malformedSsrResponses')]
    public function testItRejectsMalformedSsrResponses(string $body): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        Http::fake([
            $this->renderUrl => Http::response($body),
        ]);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    /**
     * Provide malformed SSR response bodies.
     *
     * @return array<string, array{string}>
     */
    public static function malformedSsrResponses(): array
    {
        return [
            'invalid JSON' => ['invalid json'],
            'scalar JSON' => [json_encode('invalid')],
            'empty object' => [json_encode((object) [])],
            'missing head' => [json_encode(['body' => '<div>SSR</div>'])],
            'missing body' => [json_encode(['head' => []])],
            'non-array head' => [json_encode(['head' => '<title>SSR</title>', 'body' => '<div>SSR</div>'])],
            'non-string head entry' => [json_encode(['head' => [null], 'body' => '<div>SSR</div>'])],
            'non-string body' => [json_encode(['head' => [], 'body' => []])],
        ];
    }

    #[DataProvider('ssrResponseStatuses')]
    public function testMalformedResponsesFallBackWhenHttpJsonDecodingThrows(int $status): void
    {
        ClientResponse::$defaultJsonDecodingFlags = JSON_THROW_ON_ERROR;

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        Http::fake([
            $this->renderUrl => Http::response('invalid json', $status),
        ]);

        $this->assertNull($this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]));
    }

    /**
     * Provide successful and failed SSR response statuses.
     *
     * @return array<string, array{int}>
     */
    public static function ssrResponseStatuses(): array
    {
        return [
            'successful response' => [200],
            'failed response' => [500],
        ];
    }

    public function testMalformedSuccessDispatchesFailureAndHonorsThrowOnError(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.throw_on_error' => true,
        ]);

        Http::fake([
            $this->renderUrl => Http::sequence()
                ->push(json_encode(['head' => [], 'body' => []]))
                ->push(json_encode(['head' => [], 'body' => '<div>SSR</div>'])),
        ]);

        try {
            $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
            $this->fail('The malformed SSR response did not throw an exception.');
        } catch (SsrException $exception) {
            $this->assertSame('Invalid SSR response.', $exception->event?->error);
        }

        // Backoff must be armed before throw_on_error raises the exception.
        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        Http::assertSentCount(1);
        Event::assertDispatched(
            SsrRenderFailed::class,
            fn (SsrRenderFailed $event): bool => $event->error === 'Invalid SSR response.',
        );
    }

    public function testHealthCheckTheSsrServer(): void
    {
        Http::fake([
            $this->gateway->getProductionUrl('/health') => Http::sequence()
                ->push(status: 200)
                ->push(status: 500)
                ->pushFailedConnection('Connection refused'),
        ]);

        $this->assertTrue($this->gateway->isHealthy());
        $this->assertFalse($this->gateway->isHealthy());
        $this->assertFalse($this->gateway->isHealthy());
    }

    public function testShutdownReportsTheSsrServerResponse(): void
    {
        Http::fake([
            $this->gateway->getProductionUrl('/shutdown') => Http::sequence()
                ->push(status: 200)
                ->push(status: 500),
        ]);

        $this->assertTrue($this->gateway->shutdown());
        $this->assertFalse($this->gateway->shutdown());
    }

    public function testShutdownPreservesTransportFailures(): void
    {
        Http::fake([
            $this->gateway->getProductionUrl('/shutdown') => Http::failedConnection('Connection closed'),
        ]);

        $this->expectException(ConnectionException::class);

        $this->gateway->shutdown();
    }

    public function testHealthCheckAndShutdownReportFailedResponsesWhenTheRequestThrows(): void
    {
        Http::fake([
            $this->gateway->getProductionUrl('/health') => Http::response(status: 500),
            $this->gateway->getProductionUrl('/shutdown') => Http::response(status: 500),
        ]);

        $this->gateway->configureRequestUsing(fn (PendingRequest $request): PendingRequest => $request->throw());

        $this->assertFalse($this->gateway->isHealthy());
        $this->assertFalse($this->gateway->shutdown());
    }

    public function testItUsesViteHotUrlWhenRunningHot(): void
    {
        config(['inertia.ssr.enabled' => true]);

        $this->createHotFile("http://localhost:5173/\n");

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

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://localhost:5173/__inertia_ssr');
    }

    public function testItUsesConfiguredHotUrlWhenRunningHot(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.hot_url' => 'http://localhost:4173/base/',
        ]);

        $this->createHotFile('http://localhost:5173');

        Http::fake([
            'http://localhost:4173/base/__inertia_ssr' => Http::response(json_encode([
                'head' => ['<title>Custom Hot SSR</title>'],
                'body' => '<div id="app">Custom Hot Response</div>',
            ])),
        ]);

        $response = $this->gateway->dispatch(['page' => self::EXAMPLE_PAGE_OBJECT]);

        $this->assertNotNull($response);
        $this->assertEquals('<title>Custom Hot SSR</title>', $response->head);
        $this->assertEquals('<div id="app">Custom Hot Response</div>', $response->body);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://localhost:4173/base/__inertia_ssr');
    }

    public function testFalseHotUrlUsesTheViteHotFile(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.hot_url' => false,
        ]);

        $this->createHotFile('http://localhost:5173');

        Http::fake([
            'http://localhost:5173/__inertia_ssr' => Http::response(json_encode([
                'head' => [],
                'body' => '<div id="app">Hot Response</div>',
            ])),
        ]);

        $this->assertNotNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://localhost:5173/__inertia_ssr');
    }

    public function testItFallsBackToClientRenderingWhenTheHotFileDisappears(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config(['inertia.ssr.enabled' => true]);
        $this->createHotFile();

        $gateway = new HotFileRemovedHttpGateway;

        $this->assertNull($gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Event::assertNotDispatched(SsrRenderFailed::class);
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

    public function testPassiveObserverDoesNotCauseSsrFailureEventToDispatch(): void
    {
        $observedEvents = [];
        $this->app->make(Dispatcher::class)->observe(
            SsrRenderFailed::class,
            static function (SsrRenderFailed $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
            ]), 500),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        $this->assertSame([], $observedEvents);
    }

    public function testThrowOnErrorBuildsTheExceptionWithoutDispatchingToPassiveObservers(): void
    {
        $observedEvents = [];
        $this->app->make(Dispatcher::class)->observe(
            SsrRenderFailed::class,
            static function (SsrRenderFailed $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.throw_on_error' => true,
        ]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
            ]), 500),
        ]);

        $caughtException = null;

        try {
            $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
        } catch (SsrException $exception) {
            $caughtException = $exception;
        }

        $this->assertNotNull($caughtException);
        $this->assertSame('Foo/Bar', $caughtException->component());
        $this->assertSame(SsrErrorType::BrowserApi, $caughtException->type());
        $this->assertSame([], $observedEvents);
    }

    public function testItNormalizesMalformedRemoteErrorMetadata(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => ['invalid'],
                'type' => 123,
                'hint' => false,
                'browserApi' => ['window'],
                'stack' => new stdClass,
                'sourceLocation' => 10,
            ]), 500),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Event::assertDispatched(SsrRenderFailed::class, function (SsrRenderFailed $event) {
            return $event->error === 'Unknown SSR error'
                && $event->type === SsrErrorType::Unknown
                && $event->hint === null
                && $event->browserApi === null
                && $event->stack === null
                && $event->sourceLocation === null;
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
            $this->renderUrl => Http::failedConnection('Connection refused'),
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
        $this->expectExceptionMessageIsOrContains('SSR render failed for component [Foo/Bar]: window is not defined');

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
            $this->renderUrl => Http::failedConnection('Connection refused'),
        ]);

        $this->expectException(SsrException::class);
        $this->expectExceptionMessageIsOrContains('Connection refused');

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

    public function testItDoesNotThrowExceptionWhenThrowOnErrorIsOmitted(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config(['inertia.ssr' => [
            'enabled' => true,
            'bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'error' => 'window is not defined',
                'type' => 'browser-api',
            ]), 500),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
    }

    public function testRemoteConnectionErrorDoesNotActivateTransportBackoff(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        Http::fake([
            $this->renderUrl => Http::sequence()
                ->push(json_encode([
                    'error' => 'Server down',
                    'type' => 'connection',
                ]), 500)
                ->push(json_encode([
                    'head' => ['<title>SSR</title>'],
                    'body' => '<div>SSR</div>',
                ])),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        $this->assertNotNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        Http::assertSentCount(2);
    }

    public function testConnectionFailureActivatesTransportBackoff(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        Http::fake([
            $this->renderUrl => Http::sequence()
                ->pushFailedConnection('Connection refused')
                ->push(json_encode([
                    'head' => [],
                    'body' => '<div>SSR</div>',
                ])),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        Http::assertSentCount(1);
    }

    public function testMalformedSuccessActivatesTransportBackoff(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        Http::fake([
            $this->renderUrl => Http::sequence()
                ->push(json_encode(['head' => [], 'body' => []]))
                ->push(json_encode([
                    'head' => [],
                    'body' => '<div>SSR</div>',
                ])),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        Http::assertSentCount(1);
    }

    public function testTransportBackoffResetsAfterFlushState(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        Http::fake([
            $this->renderUrl => Http::sequence()
                ->pushFailedConnection('Connection refused')
                ->push(json_encode(['head' => ['<title>SSR</title>'], 'body' => '<div>SSR</div>'])),
        ]);

        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);

        HttpGateway::flushState();

        $response = $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
        $this->assertNotNull($response);
        $this->assertSame('<div>SSR</div>', $response->body);
    }

    public function testHealthCheckBypassesTransportBackoff(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        Http::fake([
            $this->renderUrl => Http::failedConnection('Connection refused'),
            $this->gateway->getProductionUrl('/health') => Http::response(status: 200),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        $this->assertTrue($this->gateway->isHealthy());
    }

    public function testInFlightSuccessClearsTransportBackoff(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
            'inertia.ssr.backoff' => 5.0,
        ]);

        $backoff = new ReflectionProperty(HttpGateway::class, 'ssrUnavailableUntil');
        Http::fake([
            $this->renderUrl => Http::sequence()
                ->pushResponse(static function () use ($backoff): PromiseInterface {
                    $backoff->setValue(null, microtime(true) + 5.0);

                    return Http::response(json_encode(['head' => [], 'body' => '<div>First</div>']));
                })
                ->push(json_encode(['head' => [], 'body' => '<div>Second</div>'])),
        ]);

        $first = $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
        $second = $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);

        $this->assertSame('<div>First</div>', $first?->body);
        $this->assertSame('<div>Second</div>', $second?->body);
    }

    public function testItHandlesScalarJsonErrorResponseGracefully(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        Http::fake([
            $this->renderUrl => Http::sequence()
                ->push('"Internal Server Error"', 500)
                ->push(json_encode(['head' => [], 'body' => '<div>SSR</div>'])),
        ]);

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        Http::assertSentCount(1);

        Event::assertDispatched(SsrRenderFailed::class, function (SsrRenderFailed $event) {
            return $event->error === 'Unknown SSR error';
        });
    }

    #[DefineEnvironment('useCustomSsrTimeouts')]
    public function testItAppliesTheConfiguredTimeoutToTheSsrRequest(): void
    {
        $request = $this->captureSsrRequest();

        $this->assertSame(HttpGateway::CONNECTION, $request->getConnection());
        $this->assertSame(1.5, $request->getOptions()['connect_timeout']);
        $this->assertSame(7.5, $request->getOptions()['timeout']);
    }

    #[DefineEnvironment('useNullSsrTimeouts')]
    public function testItDoesNotOverrideGloballyConfiguredHttpOptions(): void
    {
        Http::globalOptions(['connect_timeout' => 3, 'timeout' => 5]);

        $request = $this->captureSsrRequest();

        $this->assertSame(3, $request->getOptions()['connect_timeout']);
        $this->assertSame(5, $request->getOptions()['timeout']);
    }

    public function testTheSsrRequestCallbackOverridesTheConfiguredTimeouts(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode(['head' => [], 'body' => ''])),
        ]);

        $this->gateway->configureRequestUsing(function (PendingRequest $request) use (&$captured): PendingRequest {
            return $captured = $request->connectTimeout(4)->timeout(9);
        });

        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);

        $this->assertSame(4, $captured->getOptions()['connect_timeout']);
        $this->assertSame(9, $captured->getOptions()['timeout']);
    }

    public function testItConfiguresTheSsrRequestUsingTheGivenCallback(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'head' => ['<title>SSR Test</title>'],
                'body' => '<div id="app">SSR Response</div>',
            ])),
        ]);

        $this->gateway->configureRequestUsing(fn (PendingRequest $request): PendingRequest => $request->withHeader('X-Tenant', 'acme'));

        $this->assertNotNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Tenant', 'acme'));
    }

    public function testItConfiguresTheSsrRequestWhenTheCallbackReturnsNothing(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'head' => ['<title>SSR Test</title>'],
                'body' => '<div id="app">SSR Response</div>',
            ])),
        ]);

        $this->gateway->configureRequestUsing(function (PendingRequest $request): void {
            $request->withHeader('X-Tenant', 'acme');
        });

        $this->assertNotNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Tenant', 'acme'));
    }

    public function testItConfiguresTheHealthCheckRequest(): void
    {
        Http::fake([
            $this->gateway->getProductionUrl('/health') => Http::response(status: 200),
        ]);

        $this->gateway->configureRequestUsing(fn (PendingRequest $request): PendingRequest => $request->withHeader('X-Tenant', 'acme'));

        $this->assertTrue($this->gateway->isHealthy());

        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Tenant', 'acme'));
    }

    public function testTheSsrRequestConfiguratorCanBeReset(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode([
                'head' => ['<title>SSR Test</title>'],
                'body' => '<div id="app">SSR Response</div>',
            ])),
        ]);

        $this->gateway->configureRequestUsing(fn (PendingRequest $request): PendingRequest => $request->withHeader('X-Tenant', 'acme'));
        $this->gateway->configureRequestUsing();

        $this->assertNotNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Http::assertSent(fn (ClientRequest $request): bool => ! $request->hasHeader('X-Tenant'));
    }

    public function testStructuredErrorsSurviveExhaustedRetries(): void
    {
        Event::fake([SsrRenderFailed::class]);

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $error = json_encode([
            'error' => 'window is not defined',
            'type' => 'browser-api',
        ]);

        Http::fake([
            $this->renderUrl => Http::sequence()
                ->push($error, 500)
                ->push($error, 500)
                ->push(json_encode(['head' => [], 'body' => '<div>SSR</div>'])),
        ]);

        $this->gateway->configureRequestUsing(fn (PendingRequest $request): PendingRequest => $request->retry(2));

        $this->assertNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));

        Event::assertDispatched(SsrRenderFailed::class, function (SsrRenderFailed $event): bool {
            return $event->error === 'window is not defined'
                && $event->type === SsrErrorType::BrowserApi;
        });

        // A structured render error is not a transport failure, so SSR stays available.
        $this->assertNotNull($this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT));
        Http::assertSentCount(3);
    }

    public function testStrayRequestsAreNotTreatedAsSsrFailures(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.bundle' => __DIR__ . '/Fixtures/ssr-bundle.js',
        ]);

        $this->expectException(StrayRequestException::class);

        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);
    }

    #[DefineEnvironment('usePartialSsrConfig')]
    public function testPartialSsrConfigUsesTransportDefaults(): void
    {
        $shippedConfig = $this->withEnvironmentValues([
            'INERTIA_SSR_CONNECT_TIMEOUT' => null,
            'INERTIA_SSR_TIMEOUT' => null,
            'INERTIA_SSR_BACKOFF' => null,
        ], fn (): array => require dirname(__DIR__, 2) . '/src/inertia/config/inertia.php');

        $gateway = new HttpGateway;

        $this->assertSame([
            'connect_timeout' => $shippedConfig['ssr']['connect_timeout'],
            'timeout' => $shippedConfig['ssr']['timeout'],
        ], Http::getConnectionOptions(HttpGateway::CONNECTION));
        $this->assertSame('http://127.0.0.1:13714/render', $gateway->getProductionUrl('/render'));
        $this->assertTrue((new ReflectionMethod($gateway, 'shouldEnsureBundleExists'))->invoke($gateway));

        $startedAt = microtime(true);
        (new ReflectionMethod($gateway, 'armTransportBackoff'))->invoke($gateway);
        $unavailableUntil = (new ReflectionProperty(HttpGateway::class, 'ssrUnavailableUntil'))->getValue();

        $this->assertEqualsWithDelta($startedAt + $shippedConfig['ssr']['backoff'], $unavailableUntil, 0.1);
    }

    /**
     * Capture the pending HTTP request that is sent to the SSR server.
     */
    protected function captureSsrRequest(): PendingRequest
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);

        Http::fake([
            $this->renderUrl => Http::response(json_encode(['head' => [], 'body' => ''])),
        ]);

        $this->gateway->configureRequestUsing(function (PendingRequest $request) use (&$captured): void {
            $captured = $request;
        });

        $this->gateway->dispatch(self::EXAMPLE_PAGE_OBJECT);

        return $captured;
    }

    /**
     * Configure custom SSR timeouts before the SSR connection is registered.
     */
    protected function useCustomSsrTimeouts(ApplicationContract $app): void
    {
        $app->make('config')->set('inertia.ssr.connect_timeout', 1.5);
        $app->make('config')->set('inertia.ssr.timeout', 7.5);
    }

    /**
     * Configure null SSR timeouts before the SSR connection is registered.
     */
    protected function useNullSsrTimeouts(ApplicationContract $app): void
    {
        $app->make('config')->set('inertia.ssr.connect_timeout', null);
        $app->make('config')->set('inertia.ssr.timeout', null);
    }

    /**
     * Configure an SSR section that omits every optional setting.
     */
    protected function usePartialSsrConfig(ApplicationContract $app): void
    {
        $app->make('config')->set('inertia.ssr', []);
    }
}

class HotFileRemovedHttpGateway extends HttpGateway
{
    /**
     * Remove the hot file immediately before resolving its URL.
     */
    protected function getHotUrl(string $path = '/'): ?string
    {
        unlink(Vite::hotFile());

        return parent::getHotUrl($path);
    }
}
