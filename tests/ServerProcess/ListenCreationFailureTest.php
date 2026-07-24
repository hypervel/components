<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Tests\ServerProcess\Fixtures\ListenableProcess;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Swoole\Coroutine as SwooleCoroutine;

class ListenCreationFailureTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testCreationFailureClosesTheUntransferredQuitChannel(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            $container = m::mock(ContainerContract::class);
            $container->shouldReceive('bound')->with('events')->andReturn(false);

            $process = new ListenableProcess($container);
            $quit = new Channel(1);

            try {
                $process->callListen($quit);
                $this->fail('Expected listener coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertTrue($quit->isClosing());
                $this->assertSame(0, $process->socketExports);
            }
        });
    }
}
