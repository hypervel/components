<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\ArtisanStarting;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\OpenTelemetryServiceProvider;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Testbench\TestCase;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;

class DisabledOpenTelemetryServiceProviderTest extends TestCase
{
    protected string|false $originalDisabled;

    protected bool $originalServerExists;

    protected mixed $originalServer;

    protected bool $originalEnvironmentExists;

    protected mixed $originalEnvironment;

    protected function setUp(): void
    {
        $this->originalDisabled = getenv('OTEL_SDK_DISABLED');
        $this->originalServerExists = array_key_exists('OTEL_SDK_DISABLED', $_SERVER);
        $this->originalServer = $_SERVER['OTEL_SDK_DISABLED'] ?? null;
        $this->originalEnvironmentExists = array_key_exists('OTEL_SDK_DISABLED', $_ENV);
        $this->originalEnvironment = $_ENV['OTEL_SDK_DISABLED'] ?? null;
        $_SERVER['OTEL_SDK_DISABLED'] = 'true';
        $_ENV['OTEL_SDK_DISABLED'] = 'true';
        putenv('OTEL_SDK_DISABLED=true');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->originalDisabled === false
                ? putenv('OTEL_SDK_DISABLED')
                : putenv("OTEL_SDK_DISABLED={$this->originalDisabled}");

            if ($this->originalServerExists) {
                $_SERVER['OTEL_SDK_DISABLED'] = $this->originalServer;
            } else {
                unset($_SERVER['OTEL_SDK_DISABLED']);
            }

            if ($this->originalEnvironmentExists) {
                $_ENV['OTEL_SDK_DISABLED'] = $this->originalEnvironment;
            } else {
                unset($_ENV['OTEL_SDK_DISABLED']);
            }
        }
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [OpenTelemetryServiceProvider::class];
    }

    public function testDisabledSdkInstallsNoopProvidersAndLazyCoroutineContextStorage(): void
    {
        $manager = $this->app->make(OpenTelemetryManager::class);

        $this->assertInstanceOf(NoopMeterProvider::class, Globals::meterProvider());
        $this->assertInstanceOf(NoopTracerProvider::class, Globals::tracerProvider());
        $this->assertInstanceOf(NoopLoggerProvider::class, Globals::loggerProvider());
        $this->assertFalse(CoroutineContext::has(CoroutineContextStorage::CONTEXT_KEY));

        $manager->bind(ProcessIdentity::eventWorker(0));

        $this->assertFalse($manager->isBound());
        $this->assertFalse(CoroutineContext::has(CoroutineContextStorage::CONTEXT_KEY));
    }

    public function testDisabledSdkRetainsConfiguredPropagation(): void
    {
        $this->assertSame(['traceparent', 'tracestate', 'baggage'], Globals::propagator()->fields());
    }

    public function testDisabledSdkRegistersNoProducingProcessLifecycleListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $this->assertFalse($events->hasListeners(BeforeHandle::class));
        $this->assertFalse($events->hasListeners(AfterExecute::class));
        $this->assertFalse($events->hasListeners(ArtisanStarting::class));
    }
}
