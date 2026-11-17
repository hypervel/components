<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Container\Container;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\Events\PipeMessage;
use Hypervel\Tests\TestCase;

class EventTest extends TestCase
{
    public function testBeforeProcessHandleHoldsProcessAndIndex(): void
    {
        $process = new class(new Container) extends AbstractProcess {
            public string $name = 'test-process';

            /**
             * Leave process execution outside this event test.
             */
            public function handle(): void
            {
            }
        };

        $event = new BeforeProcessHandle($process, 3);

        $this->assertSame($process, $event->process);
        $this->assertSame(3, $event->index);
        $this->assertSame('test-process', $event->process->name);
    }

    public function testAfterProcessHandleHoldsProcessAndIndex(): void
    {
        $process = new class(new Container) extends AbstractProcess {
            /**
             * Leave process execution outside this event test.
             */
            public function handle(): void
            {
            }
        };

        $event = new AfterProcessHandle($process, 0);

        $this->assertSame($process, $event->process);
        $this->assertSame(0, $event->index);
    }

    public function testPipeMessageHoldsData(): void
    {
        $data = ['key' => 'value', 'nested' => ['a', 'b']];
        $event = new PipeMessage($data);

        $this->assertSame($data, $event->data);
    }

    public function testPipeMessageAcceptsMixedData(): void
    {
        $stringEvent = new PipeMessage('hello');
        $this->assertSame('hello', $stringEvent->data);

        $intEvent = new PipeMessage(42);
        $this->assertSame(42, $intEvent->data);

        $nullEvent = new PipeMessage(null);
        $this->assertNull($nullEvent->data);
    }
}
