<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hyperf\Signal\SignalHandlerInterface;
use Hypervel\ServerProcess\Handlers\ProcessStopHandler;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ProcessStopHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        ProcessManager::clear();
        ProcessManager::setRunning(false);
    }

    public function testImplementsSignalHandlerInterface()
    {
        $handler = new ProcessStopHandler();
        $this->assertInstanceOf(SignalHandlerInterface::class, $handler);
    }

    public function testListensForSigtermOnProcess()
    {
        $handler = new ProcessStopHandler();
        $signals = $handler->listen();

        $this->assertCount(1, $signals);
        $this->assertSame([SignalHandlerInterface::PROCESS, SIGTERM], $signals[0]);
    }

    public function testHandleSetsProcessManagerToNotRunning()
    {
        ProcessManager::setRunning(true);
        $this->assertTrue(ProcessManager::isRunning());

        $handler = new ProcessStopHandler();
        $handler->handle(SIGTERM);

        $this->assertFalse(ProcessManager::isRunning());
    }
}
