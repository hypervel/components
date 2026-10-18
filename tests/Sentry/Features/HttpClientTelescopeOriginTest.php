<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Http;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\TelescopeServiceProvider;
use Hypervel\Telescope\Watchers\ClientRequestWatcher;
use Hypervel\Tests\Sentry\Fixtures\HttpClientOriginFixture;
use Hypervel\Tests\Sentry\TestCase;
use ReflectionMethod;
use Sentry\Tracing\Span;

/**
 * Sentry and Telescope both instrument Guzzle transfers. With both installed, Telescope
 * must still record the request and Sentry's span must still find the application caller.
 */
class HttpClientTelescopeOriginTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.tracing.http_client_requests_origin' => true,
        'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
        'telescope.enabled' => true,
        'telescope.watchers' => [
            ClientRequestWatcher::class => true,
        ],
    ];

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        // The service provider excludes `base_path('vendor')` from in-app frames, but our base path is the
        // Testbench skeleton, so exclude this repository's framework sources and vendor directory to match
        // a real application
        $app->make('config')->set('sentry.in_app_exclude', [dirname(__DIR__, 3) . '/src', dirname(__DIR__, 3) . '/vendor']);
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            TelescopeServiceProvider::class,
        ];
    }

    /**
     * Start recording in the test's coroutine.
     */
    protected function setUpInCoroutine(): void
    {
        Telescope::startRecording();
    }

    /**
     * Discard the recorded entries before the test's coroutine ends.
     */
    protected function tearDownInCoroutine(): void
    {
        // Nothing is stored, so the deferred store has no database to write to.
        Telescope::flushEntries();
    }

    public function testOriginOfASynchronousRequestIsTheApplicationCaller(): void
    {
        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        $this->assertTelescopeRecordedTheRequest();
        $this->assertSpanHasFixtureOrigin(last($transaction->getSpanRecorder()->getSpans()), 'get');
    }

    public function testOriginOfAnAsynchronousRequestIsTheApplicationCaller(): void
    {
        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->getAsync('https://example.com')->wait();

        $this->assertTelescopeRecordedTheRequest();
        $this->assertSpanHasFixtureOrigin(last($transaction->getSpanRecorder()->getSpans()), 'getAsync');
    }

    /**
     * Assert that Telescope recorded the request.
     */
    private function assertTelescopeRecordedTheRequest(): void
    {
        $entries = array_values(array_filter(
            Telescope::getEntriesQueue(),
            static fn (IncomingEntry $entry): bool => $entry->type === EntryType::CLIENT_REQUEST
        ));

        $this->assertCount(1, $entries);
        $this->assertSame('https://example.com', $entries[0]->content['uri']);
    }

    /**
     * Assert that the span's origin is the request made by the given fixture method.
     */
    private function assertSpanHasFixtureOrigin(Span $span, string $methodName): void
    {
        $method = new ReflectionMethod(HttpClientOriginFixture::class, $methodName);

        $data = $span->getData();

        $this->assertSame('http.client', $span->getOp());
        $this->assertSame($method->getFileName(), $data['code.filepath'] ?? null);
        // The request is made on the first line of the method body
        $this->assertSame($method->getStartLine() + 2, $data['code.lineno'] ?? null);
        $this->assertSame(HttpClientOriginFixture::class . '::' . $methodName, $data['code.function'] ?? null);
    }
}
