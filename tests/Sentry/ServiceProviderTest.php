<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Contracts\Http\Kernel;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Http\Request;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Sentry\Facade;
use Hypervel\Sentry\Features\Feature;
use Hypervel\Sentry\Http\FlushEventsMiddleware;
use Hypervel\Sentry\Http\SetRequestIpMiddleware;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Sentry\Tracing\Middleware as TracingMiddleware;
use Hypervel\Support\Facades\Artisan;
use Mockery as m;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sentry\State\HubInterface;
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

        config()->set('sentry.spotlight', 'http://localhost:8969/stream');
        config()->set('sentry.max_breadcrumbs', 0);

        $active = new InspectableSentryFeature($this->app);
        $this->assertTrue($active->canRecordSpansForTest());
        $this->assertFalse($active->canRecordBreadcrumbsForTest());
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
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.continue_after_response' => false,
            'sentry.tracing.missing_routes' => true,
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
        $this->bootFeatures();
    }
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
