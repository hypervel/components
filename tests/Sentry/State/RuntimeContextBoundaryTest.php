<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\State;

use Hypervel\Sentry\Hub;
use Hypervel\Sentry\State\CoroutineRuntimeContextStorage;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;

use function Hypervel\Coroutine\run;

class RuntimeContextBoundaryTest extends TestCase
{
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    public function testStartsContextWithHypervelHubAndFlushesAtCoroutineExit(): void
    {
        $storage = new CoroutineRuntimeContextStorage;
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('getOptions')->returns(new Options);
        $client->expects('flush')
            ->with(null)
            ->returns(new Result(ResultStatus::success()));
        $hub = new Hub($client);
        $boundary = new RuntimeContextBoundary($hub, $storage);

        SentrySdk::init();
        SentrySdk::setRuntimeContextStorage($storage);

        run(function () use ($boundary, $hub, $storage): void {
            $boundary->start();

            $this->assertSame($hub, SentrySdk::getCurrentRuntimeContext()->getHub());
            $this->assertSame(SentrySdk::getCurrentRuntimeContext(), $storage->get());
        });

        $this->assertNull($storage->get());
    }

    public function testReusesActiveContextWithoutRegisteringAnotherEnd(): void
    {
        $storage = new CoroutineRuntimeContextStorage;
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('getOptions')->returns(new Options);
        $client->expects('flush')
            ->with(null)
            ->returns(new Result(ResultStatus::success()));
        $boundary = new RuntimeContextBoundary(new Hub($client), $storage);

        SentrySdk::init();
        SentrySdk::setRuntimeContextStorage($storage);

        run(function () use ($boundary): void {
            $boundary->start();
            $runtimeContext = SentrySdk::getCurrentRuntimeContext();

            $boundary->start();

            $this->assertSame($runtimeContext, SentrySdk::getCurrentRuntimeContext());
        });
    }

    public function testDoesNothingOutsideACoroutine(): void
    {
        $storage = new CoroutineRuntimeContextStorage;
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('flush')->never();
        $boundary = new RuntimeContextBoundary(new Hub($client), $storage);

        SentrySdk::init();
        SentrySdk::setRuntimeContextStorage($storage);

        $boundary->start();

        $this->assertNull($storage->get());
    }
}
