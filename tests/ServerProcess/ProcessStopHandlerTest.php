<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\ServerProcess\Handlers\ProcessStopHandler;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Tests\TestCase;

class ProcessStopHandlerTest extends TestCase
{
    public function testImplementsSignalHandler(): void
    {
        $handler = new ProcessStopHandler;
        $this->assertInstanceOf(SignalHandler::class, $handler);
    }

    public function testListensForSigtermOnServerProcess(): void
    {
        $handler = new ProcessStopHandler;
        $signals = $handler->signals();

        $this->assertCount(1, $signals);
        $this->assertSame([SIGTERM], $signals[SignalHandler::SERVER_PROCESS]);
    }

    public function testHandleSetsProcessManagerToNotRunning(): void
    {
        ProcessManager::setRunning(true);
        $this->assertTrue(ProcessManager::isRunning());

        $handler = new ProcessStopHandler;
        $handler->handle(SIGTERM);

        $this->assertFalse(ProcessManager::isRunning());
    }
}
