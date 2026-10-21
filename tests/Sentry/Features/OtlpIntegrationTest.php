<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\OpenTelemetry\OpenTelemetryServiceProvider;
use Hypervel\Tests\Sentry\TestCase;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Sentry\Integration\OTLPIntegration;

use function Sentry\captureMessage;

class OtlpIntegrationTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->bind(OTLPIntegration::class, fn (): OTLPIntegration => new OTLPIntegration(setupOtlpTracesExporter: false));
        $app->make('config')->set('sentry.integrations', [
            OTLPIntegration::class,
        ]);
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            OpenTelemetryServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    public function testEventsUseTheActiveOpenTelemetrySpanAndKeepHypervelsTracerProvider(): void
    {
        $scope = Span::wrap(SpanContext::create('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', TraceFlags::SAMPLED))->activate();

        try {
            captureMessage('correlated message');
        } finally {
            $scope->detach();
        }

        $trace = $this->getLastSentryEvent()->getContexts()['trace'];

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $trace['trace_id']);
        $this->assertSame('b7ad6b7169203331', $trace['span_id']);
        $this->assertSame($this->app->make(TracerProviderInterface::class), Globals::tracerProvider());
    }
}
