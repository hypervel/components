<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Contracts\ServerProcess\ProcessInterface;
use Hypervel\ServerProcess\ProcessManager;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class ProcessManagerTest extends TestCase
{
    use VerifiesDoubles;

    public function testIsNotRunningInitially(): void
    {
        $this->assertFalse(ProcessManager::isRunning());
    }

    public function testSetRunning(): void
    {
        ProcessManager::setRunning(true);
        $this->assertTrue(ProcessManager::isRunning());

        ProcessManager::setRunning(false);
        $this->assertFalse(ProcessManager::isRunning());
    }

    public function testAllReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], ProcessManager::all());
    }

    public function testRegisterProcess(): void
    {
        $process = Double::for(ProcessInterface::class)->strict();

        ProcessManager::register($process);

        $this->assertCount(1, ProcessManager::all());
        $this->assertSame($process, ProcessManager::all()[0]);
    }

    public function testRegisterMultipleProcesses(): void
    {
        $process1 = Double::for(ProcessInterface::class)->strict();
        $process2 = Double::for(ProcessInterface::class)->strict();

        ProcessManager::register($process1);
        ProcessManager::register($process2);

        $this->assertCount(2, ProcessManager::all());
        $this->assertSame($process1, ProcessManager::all()[0]);
        $this->assertSame($process2, ProcessManager::all()[1]);
    }

    public function testFlushState(): void
    {
        ProcessManager::register(Double::for(ProcessInterface::class)->strict());
        ProcessManager::setRunning(true);
        $this->assertCount(1, ProcessManager::all());
        $this->assertTrue(ProcessManager::isRunning());

        ProcessManager::flushState();
        $this->assertSame([], ProcessManager::all());
        $this->assertFalse(ProcessManager::isRunning());
    }

    public function testRegisterThrowsWhenRunning(): void
    {
        ProcessManager::setRunning(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Processes are running');

        ProcessManager::register(Double::for(ProcessInterface::class)->strict());
    }

    public function testRegisterWorksAfterStoppingAndClearing(): void
    {
        ProcessManager::setRunning(true);
        ProcessManager::setRunning(false);

        $process = Double::for(ProcessInterface::class)->strict();
        ProcessManager::register($process);

        $this->assertCount(1, ProcessManager::all());
    }
}
