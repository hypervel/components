<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use DateTimeImmutable;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\ArtisanStarting;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Contracts\Console\Application as ConsoleApplicationContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Log\LogManager;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Facades\OpenTelemetry;
use Hypervel\OpenTelemetry\Logging\OpenTelemetryHandler;
use Hypervel\OpenTelemetry\OpenTelemetryLifecycle;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\OpenTelemetryServiceProvider;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

class OpenTelemetryServiceProviderTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [OpenTelemetryServiceProvider::class];
    }

    public function testProviderRegistersTheManagerFacadeAndStandardInterfaces(): void
    {
        $manager = $this->app->make(OpenTelemetryManager::class);

        $this->assertSame($manager, OpenTelemetry::getFacadeRoot());
        $this->assertSame($this->app->make(MeterProviderInterface::class), Globals::meterProvider());
        $this->assertSame($this->app->make(TracerProviderInterface::class), Globals::tracerProvider());
        $this->assertSame($this->app->make(LoggerProviderInterface::class), Globals::loggerProvider());
        $this->assertSame($this->app->make(TextMapPropagatorInterface::class), Globals::propagator());
        $this->assertSame($this->app->make(ResponsePropagatorInterface::class), Globals::responsePropagator());
        $this->assertInstanceOf(CoroutineContextStorage::class, Context::storage());
    }

    public function testProviderInstallsDeferredNoopHandlesBeforeWorkerBinding(): void
    {
        $manager = $this->app->make(OpenTelemetryManager::class);

        $this->assertFalse($manager->isBound());
        $this->assertFalse($manager->tracer()->isEnabled());
        $this->assertFalse($manager->logger()->isEnabled());
    }

    public function testProviderRegistersProducingProcessLifecycleListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $this->assertTrue($events->hasListeners(AfterWorkerStart::class));
        $this->assertTrue($events->hasListeners(BeforeHandle::class));
        $this->assertTrue($events->hasListeners(AfterExecute::class));
        $this->assertTrue($events->hasListeners(ArtisanStarting::class));
        $this->assertTrue($events->hasListeners(BeforeProcessHandle::class));
        $this->assertTrue($events->hasListeners(AfterProcessHandle::class));
    }

    public function testProviderBindsCliWhenArtisanStartsAfterApplicationBoot(): void
    {
        $lifecycle = m::mock(OpenTelemetryLifecycle::class);
        $lifecycle->shouldReceive('startCli')->once();
        $this->app->instance(OpenTelemetryLifecycle::class, $lifecycle);

        $this->app->make(ConsoleApplicationContract::class);
    }

    public function testProviderMergesAndPublishesConfiguration(): void
    {
        $this->assertTrue(config()->boolean('opentelemetry.enabled'));
        $this->assertSame(['tracecontext', 'baggage'], config()->array('opentelemetry.propagators'));
        $this->assertSame(
            'otlp',
            config()->array('opentelemetry.exporters.otlp')['driver'],
        );
        $this->assertSame([
            dirname(__DIR__, 2) . '/src/opentelemetry/src/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ], ServiceProvider::pathsToPublish(OpenTelemetryServiceProvider::class, 'opentelemetry-config'));
    }

    #[WithConfig('opentelemetry.enabled', false)]
    #[WithConfig('logging.channels.telemetry', [
        'driver' => 'opentelemetry',
        'name' => 'application',
    ])]
    public function testDisabledSdkStillRegistersTheNamedLogDriver(): void
    {
        $logger = $this->app->make(LogManager::class)->channel('telemetry')->getLogger();

        $this->assertInstanceOf(Monolog::class, $logger);
        $this->assertSame('application', $logger->getName());
        $this->assertCount(1, $logger->getHandlers());

        $handler = $logger->getHandlers()[0];
        $this->assertInstanceOf(OpenTelemetryHandler::class, $handler);

        $record = new LogRecord(
            new DateTimeImmutable,
            'application',
            Level::Info,
            'Application log.',
        );

        $this->assertFalse($handler->isHandling($record));
        $this->assertFalse($handler->handle($record));
    }
}
