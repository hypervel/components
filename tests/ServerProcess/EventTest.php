<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\Events\PipeMessage;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class EventTest extends TestCase
{
    public function testBeforeProcessHandleHoldsProcessAndIndex()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);
        $process = new class($container) extends AbstractProcess {
            public string $name = 'test-process';

            public function handle(): void
            {
            }
        };

        $event = new BeforeProcessHandle($process, 3);

        $this->assertSame($process, $event->process);
        $this->assertSame(3, $event->index);
        $this->assertSame('test-process', $event->process->name);
    }

    public function testAfterProcessHandleHoldsProcessAndIndex()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);
        $process = new class($container) extends AbstractProcess {
            public function handle(): void
            {
            }
        };

        $event = new AfterProcessHandle($process, 0);

        $this->assertSame($process, $event->process);
        $this->assertSame(0, $event->index);
    }

    public function testPipeMessageHoldsData()
    {
        $data = ['key' => 'value', 'nested' => ['a', 'b']];
        $event = new PipeMessage($data);

        $this->assertSame($data, $event->data);
    }

    public function testPipeMessageAcceptsMixedData()
    {
        $stringEvent = new PipeMessage('hello');
        $this->assertSame('hello', $stringEvent->data);

        $intEvent = new PipeMessage(42);
        $this->assertSame(42, $intEvent->data);

        $nullEvent = new PipeMessage(null);
        $this->assertNull($nullEvent->data);
    }
}
