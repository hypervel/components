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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

use function Hypervel\Coroutine\run;

class OpenTelemetryLifecycleNonCoroutineTest extends TestCase
{
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    public function testNonCoroutineCommandDoesNotPreventNestedCoroutineScheduling(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->times(2)->returns(false, true);
        $manager->expects('bind')->with(Argument::satisfies(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::CLI,
        ));
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('start');
        $scheduler->expects('stop');
        $scheduler->expects('shutdown')->returns(true);
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')->with(Terminating::class, Argument::type('callable'));
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
