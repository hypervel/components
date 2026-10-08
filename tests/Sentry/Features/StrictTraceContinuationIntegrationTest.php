<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Generator;
use Hypervel\Routing\Router;
use Hypervel\Tests\Sentry\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StrictTraceContinuationIntegrationTest extends TestCase
{
    private const string INCOMING_TRACE_ID = '566e3688a61d4bc888951642d6f14a19';

    private const string INCOMING_PARENT_SPAN_ID = '566e3688a61d4bc8';

    private const string INCOMING_SENTRY_TRACE_HEADER = self::INCOMING_TRACE_ID . '-' . self::INCOMING_PARENT_SPAN_ID . '-1';

    /**
     * Register the test routes.
     */
    private function registerRoutes(): void
    {
        $this->app->make('router')->group(['prefix' => 'sentry'], function (Router $router): void {
            $router->get('/strict-trace-continuation', function (): string {
                return 'ok';
            });
        });
    }

    #[DataProvider('strictTraceContinuationDataProvider')]
    public function testStrictTraceContinuation(?int $orgId, bool $strictTraceContinuation, string $baggage, bool $expectedContinueTrace): void
    {
        $config = [
            'sentry.traces_sample_rate' => 1.0,
            'sentry.strict_trace_continuation' => $strictTraceContinuation,
        ];

        if ($orgId !== null) {
            $config['sentry.org_id'] = $orgId;
        }

        $this->resetApplicationWithConfig($config);
        $this->registerRoutes();

        $server = [
            'HTTP_SENTRY_TRACE' => self::INCOMING_SENTRY_TRACE_HEADER,
        ];

        if ($baggage !== '') {
            $server['HTTP_BAGGAGE'] = $baggage;
        }

        $response = $this->call('GET', '/sentry/strict-trace-continuation', [], [], [], $server);

        $this->assertSame(200, $response->getStatusCode());

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);

        $traceContext = $transaction->getContexts()['trace'];

        if ($expectedContinueTrace) {
            $this->assertSame(self::INCOMING_TRACE_ID, $traceContext['trace_id']);
            $this->assertSame(self::INCOMING_PARENT_SPAN_ID, $traceContext['parent_span_id']);
        } else {
            $this->assertNotSame(self::INCOMING_TRACE_ID, $traceContext['trace_id']);
            $this->assertArrayNotHasKey('parent_span_id', $traceContext);
        }
    }

    /**
     * Provide org ID, strict mode and baggage combinations with whether the incoming trace is continued.
     */
    public static function strictTraceContinuationDataProvider(): Generator
    {
        yield [1, false, 'sentry-org_id=1', true];
        yield [1, false, '', true];
        yield [null, false, 'sentry-org_id=1', true];
        yield [null, false, '', true];
        yield [2, false, 'sentry-org_id=1', false];
        yield [1, true, 'sentry-org_id=1', true];
        yield [1, true, '', false];
        yield [null, true, 'sentry-org_id=1', false];
        yield [null, true, '', true];
        yield [2, true, 'sentry-org_id=1', false];
    }

    public function testConfiguredOrgIdOverridesDsnOrgIdForStrictTraceContinuation(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => 'http://publickey@o1.example.com/123',
            'sentry.org_id' => 2,
            'sentry.strict_trace_continuation' => true,
            'sentry.traces_sample_rate' => 1.0,
        ]);
        $this->registerRoutes();

        $response = $this->call('GET', '/sentry/strict-trace-continuation', [], [], [], [
            'HTTP_SENTRY_TRACE' => self::INCOMING_SENTRY_TRACE_HEADER,
            'HTTP_BAGGAGE' => 'sentry-org_id=2',
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);

        $traceContext = $transaction->getContexts()['trace'];

        $this->assertSame(self::INCOMING_TRACE_ID, $traceContext['trace_id']);
        $this->assertSame(self::INCOMING_PARENT_SPAN_ID, $traceContext['parent_span_id']);
    }

    public function testIncomingTraceHeadersPopulatePropagationContextWhenTracingIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.enable_tracing' => false,
        ]);

        // The request runs in its own coroutine with its own scope, so read it while handling the request
        $propagationContext = null;

        $this->app->make('router')->get('/sentry/strict-trace-continuation', function () use (&$propagationContext): string {
            $propagationContext = $this->getCurrentSentryScope()->getPropagationContext();

            return 'ok';
        });

        $response = $this->call('GET', '/sentry/strict-trace-continuation', [], [], [], [
            'HTTP_SENTRY_TRACE' => self::INCOMING_SENTRY_TRACE_HEADER,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSentryTransactionCount(0);

        $this->assertNotNull($propagationContext);
        $this->assertSame(self::INCOMING_TRACE_ID, (string) $propagationContext->getTraceId());
        $this->assertSame(self::INCOMING_PARENT_SPAN_ID, (string) $propagationContext->getParentSpanId());
    }
}
