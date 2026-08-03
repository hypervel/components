<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Signal\SignalManager;
use Hypervel\Support\SafeCaller;
use Hypervel\Tests\Signal\Fixtures\SignalHandlerStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

class SignalManagerNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testListenDoesNotResolveHandlersOutsideACoroutine(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturn(new Repository([
            'signal' => ['handlers' => [SignalHandlerStub::class]],
        ]));
        $container->shouldReceive('make')->with(SafeCaller::class)->andReturn(new SafeCaller($container));
        $container->shouldNotReceive('make')->with(SignalHandlerStub::class);
        $manager = new SignalManager($container);

        $this->assertFalse(Coroutine::inCoroutine());

        $manager->listen(SignalHandler::WORKER);
    }
}
