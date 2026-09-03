<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry;

use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\ArtisanStarting;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Console\Application as ConsoleApplicationContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Log\LogManager;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Deferred\Logs\DeferredLoggerProvider;
use Hypervel\OpenTelemetry\Deferred\Metrics\DeferredMeterProvider;
use Hypervel\OpenTelemetry\Deferred\Trace\DeferredTracerProvider;
use Hypervel\OpenTelemetry\Logging\LogChannel;
use Hypervel\OpenTelemetry\Support\ConfigurationNormalizer;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\PropagatorFactory;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Support\ServiceProvider;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use Psr\Log\LoggerInterface;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/opentelemetry.php', 'opentelemetry');

        $config = $this->app->make(Repository::class);
        $enabled = $config->boolean('opentelemetry.enabled');
        $propagatorFactory = $this->app->make(PropagatorFactory::class);
        $textMapPropagator = $propagatorFactory->text($config->array('opentelemetry.propagators'));
        $responsePropagator = $propagatorFactory->response($config->array('opentelemetry.response_propagators'));
        $deferredMeterProvider = $enabled ? new DeferredMeterProvider : null;
        $deferredTracerProvider = $enabled ? new DeferredTracerProvider : null;
        $deferredLoggerProvider = $enabled ? new DeferredLoggerProvider : null;
        $meterProvider = $deferredMeterProvider ?? new NoopMeterProvider;
        $tracerProvider = $deferredTracerProvider ?? new NoopTracerProvider;
        $loggerProvider = $deferredLoggerProvider ?? NoopLoggerProvider::getInstance();
        $baseContext = Configurator::create()
            ->withMeterProvider($meterProvider)
            ->withTracerProvider($tracerProvider)
            ->withLoggerProvider($loggerProvider)
            ->withPropagator($textMapPropagator)
            ->withResponsePropagator($responsePropagator)
            ->storeInContext(Context::getRoot());

        Context::setStorage(new CoroutineContextStorage($baseContext));

        if (! $this->app->bound(ClockInterface::class)) {
            $this->app->instance(ClockInterface::class, Clock::getDefault());
        }

        $this->app->instance(MeterProviderInterface::class, $meterProvider);
        $this->app->instance(TracerProviderInterface::class, $tracerProvider);
        $this->app->instance(LoggerProviderInterface::class, $loggerProvider);
        $this->app->instance(TextMapPropagatorInterface::class, $textMapPropagator);
        $this->app->instance(ResponsePropagatorInterface::class, $responsePropagator);
        $this->app->singleton(OpenTelemetryManager::class, function () use (
            $config,
            $meterProvider,
            $tracerProvider,
            $loggerProvider,
            $deferredMeterProvider,
            $deferredTracerProvider,
            $deferredLoggerProvider,
            $textMapPropagator,
            $responsePropagator,
            $enabled,
        ): OpenTelemetryManager {
            return new OpenTelemetryManager(
                $this->app,
                $config,
                $this->app->make(ConfigurationNormalizer::class),
                $this->app->make(ProviderFactory::class),
                $this->app->make(ExceptionContextRegistry::class),
                $this->app->make(OperationOrigin::class),
                $meterProvider,
                $tracerProvider,
                $loggerProvider,
                $deferredMeterProvider,
                $deferredTracerProvider,
                $deferredLoggerProvider,
                $textMapPropagator,
                $responsePropagator,
                $enabled,
            );
        });
    }

    /**
     * Boot the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/opentelemetry.php' => config_path('opentelemetry.php'),
            ], 'opentelemetry-config');
        }

        $this->callAfterResolving(LogManager::class, static function (LogManager $logs): void {
            $logs->extend('opentelemetry', static function (Application $app, array $config): LoggerInterface {
                return $app->make(LogChannel::class)($config);
            });
        });

        if (! $this->app->make(Repository::class)->boolean('opentelemetry.enabled')) {
            return;
        }

        $events = $this->app->make('events');
        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
            $this->app->make(OpenTelemetryLifecycle::class)->startWorker($event);
        });

        if ($this->app->runningInConsole()) {
            $events->listen(BeforeHandle::class, function (BeforeHandle $event): void {
                $this->app->make(OpenTelemetryLifecycle::class)->beginCliCommand();
            });
            $events->listen(AfterExecute::class, function (AfterExecute $event): void {
                $this->app->make(OpenTelemetryLifecycle::class)->endCliCommand();
            });

            $this->app->booted(function () use ($events): void {
                $events->listen(ArtisanStarting::class, function (ArtisanStarting $event): void {
                    $this->app->make(OpenTelemetryLifecycle::class)->startCli();
                });

                if ($this->app->resolved(ConsoleApplicationContract::class)) {
                    $this->app->make(OpenTelemetryLifecycle::class)->startCli();
                }
            });
        }

        $events->listen(BeforeProcessHandle::class, function (BeforeProcessHandle $event): void {
            $this->app->make(OpenTelemetryLifecycle::class)->startProcess($event);
        });
        $events->listen(AfterProcessHandle::class, function (AfterProcessHandle $event): void {
            $this->app->make(OpenTelemetryLifecycle::class)->finishProcess($event);
        });
    }

    /**
     * Get configuration arrays whose entries should be merged by name.
     */
    protected function mergeableOptions(string $name): array
    {
        return ['resource_attributes', 'exporters', 'instrumentation'];
    }
}
