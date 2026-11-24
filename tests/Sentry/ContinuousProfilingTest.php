<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Sentry\Hub;
use Hypervel\Sentry\State\CoroutineRuntimeContextStorage;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use Hypervel\Sentry\Tracing\Middleware;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Profiling\Profiles;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpFoundation\Response;

#[RequiresPhpExtension('excimer')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ContinuousProfilingTest extends TestCase
{
    use VerifiesDoubles;

    /** @var list<Event> */
    private array $events = [];

    public function testTraceLifecycleSharesAWorkerRecordingUntilTheLastRootFinishes(): void
    {
        $hub = $this->traceHub();
        $first = $hub->startTransaction(new TransactionContext('first'));
        $profilerId = $first->getData('profiler_id');

        try {
            $second = $hub->startTransaction(new TransactionContext('second'));
            try {
                $this->assertNotNull($profilerId);
                $this->assertSame($profilerId, $second->getData('profiler_id'));
                $this->assertSame('main', $first->getData('thread.name'));
                $first->finish();
                $this->assertSame($profilerId, Profiles::getInstance()->continuousProfiler()->getProfilerId());
            } finally {
                $second->finish();
            }
        } finally {
            $first->finish();
        }

        $this->assertFalse(Profiles::getInstance()->continuousProfiler()->isRunning());
    }

    public function testDiscardingTheOnlyRootStopsTheWorkerRecording(): void
    {
        $hub = $this->traceHub();
        Container::getInstance()->instance(HubInterface::class, $hub);
        $storage = new CoroutineRuntimeContextStorage;
        SentrySdk::setRuntimeContextStorage($storage);
        (new RuntimeContextBoundary($hub, $storage))->start();
        $middleware = new Middleware(continueAfterResponse: false);
        $request = Request::create('/missing');
        $recorder = Profiles::getInstance()->continuousProfiler();

        try {
            $response = $middleware->handle($request, static fn (): Response => new Response('', 404));
            $this->assertTrue($recorder->isRunning());
            $middleware->terminate($request, $response);
            $this->assertFalse($recorder->isRunning());
        } finally {
            $recorder->stop();
        }
    }

    #[TestWith([true, true])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([false, false])]
    public function testBindingAClientDecidesAnAlreadyRunningManualSession(bool $duringConstruction, bool $enabled): void
    {
        SentrySdk::init();
        $profiles = Profiles::getInstance();
        $profiles->start();

        try {
            $this->assertTrue($profiles->continuousProfiler()->isRunning());
            $client = ClientBuilder::create([
                'default_integrations' => false,
                'profile_session_sample_rate' => $enabled ? 1.0 : 0.0,
                'transport' => $this->transport(),
            ])->getClient();
            if ($duringConstruction) {
                $hub = new Hub($client);
            } else {
                $hub = new Hub;
                $hub->bindClient($client);
            }
            SentrySdk::setCurrentHub($hub);

            $this->assertSame($enabled, $profiles->continuousProfiler()->isRunning());
            if ($enabled) {
                $deadline = hrtime(true) + 100_000_000;
                while (hrtime(true) < $deadline);
            }
        } finally {
            $profiles->stop();
        }

        $this->assertSame($enabled ? [EventType::profileChunk()] : [], array_map(static fn (Event $event): EventType => $event->getType(), $this->events));
    }

    /**
     * Bind a Hub that profiles sampled root spans.
     */
    private function traceHub(): Hub
    {
        $hub = new Hub(ClientBuilder::create([
            'default_integrations' => false,
            'traces_sample_rate' => 1.0,
            'profile_session_sample_rate' => 1.0,
            'profile_lifecycle' => 'trace',
            'transport' => $this->transport(),
        ])->getClient());
        SentrySdk::setCurrentHub($hub);

        return $hub;
    }

    /**
     * Collect events without making network requests.
     */
    private function transport(): TransportInterface
    {
        $transport = Double::for(TransportInterface::class)->strict();
        $transport->allows('send')->resolves(function (Event $event): Result {
            $this->events[] = $event;

            return new Result(ResultStatus::success(), $event);
        });
        $transport->allows('close')->returns(new Result(ResultStatus::success()));

        return $transport;
    }
}
