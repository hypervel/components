<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\ArtisanStarting;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\OpenTelemetry\OpenTelemetryServiceProvider;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Testbench\TestCase;

class ServerOpenTelemetryServiceProviderTest extends TestCase
{
    protected string|false $originalRunningInConsole;

    protected function setUp(): void
    {
        $this->originalRunningInConsole = getenv('APP_RUNNING_IN_CONSOLE');
        putenv('APP_RUNNING_IN_CONSOLE=false');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->originalRunningInConsole === false
                ? putenv('APP_RUNNING_IN_CONSOLE')
                : putenv("APP_RUNNING_IN_CONSOLE={$this->originalRunningInConsole}");
        }
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [OpenTelemetryServiceProvider::class];
    }

    public function testServerModeRegistersNoStandaloneCliLifecycle(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $this->assertFalse($this->app->runningInConsole());
        $this->assertFalse($events->hasListeners(BeforeHandle::class));
        $this->assertFalse($events->hasListeners(AfterExecute::class));
        $this->assertFalse($events->hasListeners(ArtisanStarting::class));
        $this->assertTrue($events->hasListeners(AfterWorkerStart::class));
        $this->assertTrue($events->hasListeners(BeforeProcessHandle::class));
        $this->assertTrue($events->hasListeners(AfterProcessHandle::class));
    }
}
