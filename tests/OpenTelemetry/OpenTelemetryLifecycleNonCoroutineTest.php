<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\OpenTelemetry\OpenTelemetryLifecycle;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExportScheduler;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\run;

class OpenTelemetryLifecycleNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testNonCoroutineCommandDoesNotPreventNestedCoroutineScheduling(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->twice()->andReturn(false, true);
        $manager->shouldReceive('bind')->once()->withArgs(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::CLI,
        );
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('start')->once();
        $scheduler->shouldReceive('stop')->once();
        $scheduler->shouldReceive('shutdown')->once()->andReturnTrue();
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')->once()->with(Terminating::class, m::type('callable'));
        $lifecycle = new OpenTelemetryLifecycle(
            $manager,
            $scheduler,
            new Repository(['opentelemetry' => ['server_processes' => ['except' => []]]]),
            $events,
        );

        $lifecycle->startCli();
        $lifecycle->beginCliCommand();

        $this->assertTrue(run(function () use ($lifecycle): void {
            $lifecycle->beginCliCommand();
            $lifecycle->endCliCommand();
        }));

        $lifecycle->endCliCommand();
        $lifecycle->terminate(new Terminating);
    }
}
