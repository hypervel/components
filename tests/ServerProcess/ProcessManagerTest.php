<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\ServerProcess\ProcessInterface;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class ProcessManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        ProcessManager::flushState();
    }

    public function testIsNotRunningInitially()
    {
        $this->assertFalse(ProcessManager::isRunning());
    }

    public function testSetRunning()
    {
        ProcessManager::setRunning(true);
        $this->assertTrue(ProcessManager::isRunning());

        ProcessManager::setRunning(false);
        $this->assertFalse(ProcessManager::isRunning());
    }

    public function testAllReturnsEmptyArrayInitially()
    {
        $this->assertSame([], ProcessManager::all());
    }

    public function testRegisterProcess()
    {
        $process = m::mock(ProcessInterface::class);

        ProcessManager::register($process);

        $this->assertCount(1, ProcessManager::all());
        $this->assertSame($process, ProcessManager::all()[0]);
    }

    public function testRegisterMultipleProcesses()
    {
        $process1 = m::mock(ProcessInterface::class);
        $process2 = m::mock(ProcessInterface::class);

        ProcessManager::register($process1);
        ProcessManager::register($process2);

        $this->assertCount(2, ProcessManager::all());
        $this->assertSame($process1, ProcessManager::all()[0]);
        $this->assertSame($process2, ProcessManager::all()[1]);
    }

    public function testFlushState()
    {
        ProcessManager::register(m::mock(ProcessInterface::class));
        ProcessManager::setRunning(true);
        $this->assertCount(1, ProcessManager::all());
        $this->assertTrue(ProcessManager::isRunning());

        ProcessManager::flushState();
        $this->assertSame([], ProcessManager::all());
        $this->assertFalse(ProcessManager::isRunning());
    }

    public function testRegisterThrowsWhenRunning()
    {
        ProcessManager::setRunning(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Processes are running');

        ProcessManager::register(m::mock(ProcessInterface::class));
    }

    public function testRegisterWorksAfterStoppingAndClearing()
    {
        ProcessManager::setRunning(true);
        ProcessManager::setRunning(false);

        $process = m::mock(ProcessInterface::class);
        ProcessManager::register($process);

        $this->assertCount(1, ProcessManager::all());
    }
}
