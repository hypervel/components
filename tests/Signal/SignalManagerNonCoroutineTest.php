<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\SignalRegistry;
use Hypervel\Signal\SignalManager;
use Hypervel\Support\SafeCaller;
use Hypervel\Tests\Signal\Fixtures\SignalHandlerStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SignalManagerNonCoroutineTest extends TestCase
{
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    public function testListenDoesNotResolveHandlersOutsideACoroutine(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('make')->with(ConfigContract::class)->returns(new Repository([
            'signal' => ['handlers' => [SignalHandlerStub::class]],
        ]));
        $container->allows('make')->with(SafeCaller::class)->returns(new SafeCaller($container->instance()));
        $container->allows('make')->with(SignalRegistry::class)->returns(new SignalRegistry);
        $container->expects('make')->with(SignalHandlerStub::class)->never();
        $manager = new SignalManager($container->instance());

        $this->assertFalse(Coroutine::inCoroutine());

        $manager->listen(SignalHandler::WORKER);
    }
}
