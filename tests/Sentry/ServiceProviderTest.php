<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Http\Request;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Sentry\Facade;
use Hypervel\Sentry\Features\Feature;
use Hypervel\Sentry\Http\FlushEventsMiddleware;
use Hypervel\Sentry\Http\SetRequestIpMiddleware;
use Hypervel\Sentry\Hub;
use Hypervel\Sentry\SentryConfig;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Sentry\Tracing\Middleware as TracingMiddleware;
use Hypervel\Support\Facades\Artisan;
use LogicException;
use Mockery as m;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub as SdkHub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Symfony\Component\HttpFoundation\Response;

class ServiceProviderTest extends SentryTestCase
{
    protected array $setupConfig = [
        'sentry.error_types' => E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED,
    ];

    public function testIsBound(): void
    {
        $this->assertTrue(app()->bound('sentry'));
        $this->assertSame(app('sentry'), Facade::getFacadeRoot());
        $this->assertInstanceOf(HubInterface::class, app('sentry'));
    }

    public function testRegisteringASecondProviderFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Sentry provider [' . ConflictingSentryServiceProvider::class . '] cannot be registered because another Sentry provider is already registered. Add [hypervel/sentry] to [extra.hypervel.dont-discover] before registering a custom provider, or remove the custom provider.'
        );

        $this->app->register(ConflictingSentryServiceProvider::class);
    }

    public function testEnvironment(): void
    {
        $this->assertEquals('testing', app('sentry')->getClient()->getOptions()->getEnvironment());
    }

    public function testDsnWasSetFromConfig(): void
    {
        $options = app('sentry')->getClient()->getOptions();

        $this->assertEquals('https://sentry.dev', $options->getDsn()->getScheme() . '://' . $options->getDsn()->getHost());
        $this->assertEquals(123, $options->getDsn()->getProjectId());
        $this->assertEquals('publickey', $options->getDsn()->getPublicKey());
    }

    public function testScopeConfiguredBeforeClientResolutionIsPreserved(): void
    {
        SentrySdk::init();
        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope): void {
            $scope->setTag('configured_before_client', 'preserved');
        });
        $this->app->forgetInstance(HubInterface::class);
        CoroutineContext::forget(Hub::CONTEXT_STACK_KEY);

        $event = Event::createEvent();
        $this->getSentryHubFromContainer()->configureScope(
            static function (Scope $scope) use (&$event): void {
                $event = $scope->applyToEvent($event);
            },
        );

        $this->assertSame('preserved', $event->getTags()['configured_before_client'] ?? null);
    }

    public function testErrorTypesWasSetFromConfig(): void
    {
        $this->assertEquals(
            E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED,
            app('sentry')->getClient()->getOptions()->getErrorTypes()
        );
    }

    public function testArtisanCommandsAreRegistered(): void
    {
        $this->assertArrayHasKey('sentry:test', Artisan::all());
        $this->assertArrayHasKey('sentry:publish', Artisan::all());
    }

    public function testRootTelemetryFlushesAtApplicationTermination(): void
    {
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('flush')
            ->once()
            ->withNoArgs()
            ->andReturn(new Result(ResultStatus::success()));
        $previousHub = SentrySdk::getCurrentHub();

        try {
            SentrySdk::setCurrentHub(new SdkHub($client));

            $this->app->terminate();
        } finally {
            SentrySdk::setCurrentHub($previousHub);
        }
    }

    public function testApplicationTerminationDoesNotFlushAnActiveExecutionContext(): void
    {
        $flushed = false;
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')
            ->once()
            ->andReturn(new Options);
        $client->shouldReceive('flush')
            ->once()
            ->with(null)
            ->andReturnUsing(static function () use (&$flushed): Result {
                $flushed = true;

                return new Result(ResultStatus::success());
            });
        $previousHub = SentrySdk::getCurrentHub();
        $hub = new SdkHub($client);

        try {
            SentrySdk::setCurrentHub($hub);
            SentrySdk::startContext($hub);

            $this->app->terminate();

            $this->assertFalse($flushed);

            SentrySdk::endContext();

            $this->assertTrue($flushed);
        } finally {
            SentrySdk::endContext();
            SentrySdk::setCurrentHub($previousHub);
        }
    }

    public function testMiddlewareRegistersThroughTheKernelContract(): void
    {
        $kernel = m::mock(Kernel::class);
        $kernel->shouldReceive('prependMiddleware')
            ->once()
            ->with(TracingMiddleware::class)
            ->ordered()
            ->andReturnSelf();
        $kernel->shouldReceive('prependMiddleware')
            ->once()
            ->with(FlushEventsMiddleware::class)
            ->ordered()
            ->andReturnSelf();
        $kernel->shouldNotReceive('pushMiddleware');
        $this->app->instance(Kernel::class, $kernel);

        (new InspectableSentryServiceProvider($this->app))
            ->registerMiddlewareForTest();
    }

    public function testRequestIpMiddlewareIsRegisteredWhenPiiIsEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
        ]);
        $kernel = m::mock(Kernel::class);
        $kernel->shouldReceive('prependMiddleware')
            ->once()
            ->with(TracingMiddleware::class)
            ->ordered()
            ->andReturnSelf();
        $kernel->shouldReceive('prependMiddleware')
            ->once()
            ->with(FlushEventsMiddleware::class)
            ->ordered()
            ->andReturnSelf();
        $kernel->shouldReceive('pushMiddleware')
            ->once()
            ->with(SetRequestIpMiddleware::class)
            ->ordered()
            ->andReturnSelf();
        $this->app->instance(Kernel::class, $kernel);

        (new InspectableSentryServiceProvider($this->app))
            ->registerMiddlewareForTest();
    }

    public function testLegacyAndZeroRateTracingOptionsKeepFeatureSpansEnabled(): void
    {
        config()->set('sentry.enable_tracing', true);
        config()->set('sentry.traces_sample_rate', null);
        config()->set('sentry.traces_sampler', null);

        $this->assertTrue((new InspectableSentryFeature($this->app))->canRecordSpansForTest());

        config()->set('sentry.enable_tracing', null);
        config()->set('sentry.traces_sample_rate', 0.0);

        $this->assertTrue((new InspectableSentryFeature($this->app))->canRecordSpansForTest());
    }

    public function testFeatureCapabilitiesRequireAnActiveEndpointAndUsableBreadcrumbLimit(): void
    {
        config()->set('sentry.dsn', null);
        config()->set('sentry.spotlight', false);
        config()->set('sentry.traces_sample_rate', 1.0);

        $inactive = new InspectableSentryFeature($this->app);
        $this->assertFalse($inactive->canRecordSpansForTest());
        $this->assertFalse($inactive->canRecordBreadcrumbsForTest());

        config()->set('sentry.spotlight', '0');

        $this->assertFalse((new InspectableSentryFeature($this->app))->canRecordSpansForTest());

        config()->set('sentry.spotlight', 'http://localhost:8969/stream');
        config()->set('sentry.max_breadcrumbs', 0);

        $active = new InspectableSentryFeature($this->app);
        $this->assertTrue($active->canRecordSpansForTest());
        $this->assertFalse($active->canRecordBreadcrumbsForTest());
    }

    public function testPartialFeatureRecordsUseSharedOptionalDefaults(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs' => [
                'logs' => false,
                'custom' => true,
            ],
            'sentry.tracing' => [
                'sql_queries' => false,
                'custom' => true,
            ],
        ]);

        $config = (new InspectableSentryServiceProvider($this->app))->userConfigForTest();

        $this->assertSame($this->app->make(SentryConfig::class)->all(), $config);
        $this->assertFalse($config['breadcrumbs']['logs']);
        $this->assertTrue($config['breadcrumbs']['cache']);
        $this->assertTrue($config['breadcrumbs']['custom']);
        $this->assertFalse($config['tracing']['sql_queries']);
        $this->assertSame(100, $config['tracing']['sql_origin_threshold_ms']);
        $this->assertTrue($config['tracing']['custom']);
    }

    public function testSpotlightUrlRegistersTheGuzzleAspect(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => null,
            'sentry.spotlight' => 'http://localhost:8969/stream',
            'sentry_test.override_dsn' => true,
        ]);

        $this->assertNotEmpty(AspectCollector::getRule(GuzzleHttpClientAspect::class));
    }

    public function testFeatureFailureIsLoggedWithoutOverwritingItsInstanceOrSkippingBoot(): void
    {
        $exception = new RuntimeException('Feature registration failed.');
        $feature = new FailingRegistrationSentryFeature($this->app);
        $feature->exception = $exception;
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message, array $context) use ($exception): bool {
                return str_contains($message, 'failed during [register]')
                    && str_contains($message, 'effects applied before the failure remain in place')
                    && str_contains($message, 'will not be retried for this worker lifetime')
                    && $context === [
                        'feature' => FailingRegistrationSentryFeature::class,
                        'phase' => 'register',
                        'exception' => $exception,
                    ];
            });
        $this->app->instance('log', $logger);
        $this->app->instance(FailingRegistrationSentryFeature::class, $feature);
        config()->set('sentry.features', [FailingRegistrationSentryFeature::class]);
        $provider = new InspectableSentryServiceProvider($this->app);

        $provider->registerFeaturesForTest();
        $provider->bootFeaturesForTest();

        $this->assertSame($feature, $this->app->make(FailingRegistrationSentryFeature::class));
        $this->assertTrue($feature->booted);
    }

    public function testTracingMiddlewareHonorsDisabledAfterResponseContinuation(): void
    {
        $tracingConfig = config()->array('sentry.tracing');
        $tracingConfig['continue_after_response'] = false;
        $tracingConfig['missing_routes'] = true;

        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing' => $tracingConfig,
        ]);
        $middleware = $this->app->make(TracingMiddleware::class);
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, static fn () => new Response('OK'));
        $middleware->terminate($request, $response);

        $this->assertSentryTransactionCount(1);
    }
}

class InspectableSentryServiceProvider extends SentryServiceProvider
{
    /**
     * Retrieve the user configuration for inspection.
     */
    public function userConfigForTest(): array
    {
        return $this->getUserConfig();
    }

    /**
     * Register middleware for inspection.
     */
    public function registerMiddlewareForTest(): void
    {
        $this->registerMiddleware();
    }

    /**
     * Register features for inspection.
     */
    public function registerFeaturesForTest(): void
    {
        $this->registerFeatures();
    }

    /**
     * Boot features for inspection.
     */
    public function bootFeaturesForTest(): void
    {
        $this->bootFeatures($this->isActive());
    }
}

class ConflictingSentryServiceProvider extends SentryServiceProvider
{
    public static string $abstract = 'custom-sentry';
}

class InspectableSentryFeature extends Feature
{
    public function isApplicable(): bool
    {
        return true;
    }

    /**
     * Determine if spans can be recorded.
     */
    public function canRecordSpansForTest(): bool
    {
        return $this->canRecordSpans();
    }

    /**
     * Determine if breadcrumbs can be recorded.
     */
    public function canRecordBreadcrumbsForTest(): bool
    {
        return $this->canRecordBreadcrumbs();
    }
}

class FailingRegistrationSentryFeature extends Feature
{
    public RuntimeException $exception;

    public bool $booted = false;

    public function isApplicable(): bool
    {
        return true;
    }

    public function register(): void
    {
        throw $this->exception;
    }

    public function onBoot(): void
    {
        $this->booted = true;
    }
}
