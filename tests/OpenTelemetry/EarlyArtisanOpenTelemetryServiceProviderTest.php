<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\OpenTelemetry\OpenTelemetryLifecycle;
use Hypervel\OpenTelemetry\OpenTelemetryServiceProvider;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

class EarlyArtisanOpenTelemetryServiceProviderTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ConstructArtisanBeforeOpenTelemetryProvider::class,
            OpenTelemetryServiceProvider::class,
        ];
    }

    public function testProviderBindsCliAfterAnEarlierDirectArtisanConstruction(): void
    {
        $lifecycle = $this->app->make(OpenTelemetryLifecycle::class);

        $this->assertInstanceOf(RecordingOpenTelemetryLifecycle::class, $lifecycle);
        $this->assertSame(1, $lifecycle->starts);
    }
}

class ConstructArtisanBeforeOpenTelemetryProvider extends ServiceProvider
{
    /**
     * Register the test lifecycle.
     */
    public function register(): void
    {
        $this->app->instance(OpenTelemetryLifecycle::class, new RecordingOpenTelemetryLifecycle);
    }

    /**
     * Construct Artisan before OpenTelemetry registers its starting listener.
     */
    public function boot(): void
    {
        $this->app->make(Kernel::class)->getArtisan();
    }
}

class RecordingOpenTelemetryLifecycle extends OpenTelemetryLifecycle
{
    public int $starts = 0;

    /**
     * Create an isolated lifecycle test double.
     */
    public function __construct()
    {
    }

    /**
     * Record a standalone CLI bind.
     */
    public function startCli(): void
    {
        ++$this->starts;
    }
}
