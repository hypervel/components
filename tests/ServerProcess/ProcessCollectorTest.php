<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\ServerProcess\ProcessCollector;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Swoole\Process;

class ProcessCollectorTest extends TestCase
{
    use VerifiesDoubles;

    public function testIsEmptyInitially(): void
    {
        $this->assertTrue(ProcessCollector::isEmpty());
    }

    public function testAddAndGetByName(): void
    {
        $process = Double::for(Process::class)->strict();

        ProcessCollector::add('worker', $process);

        $this->assertSame([$process], ProcessCollector::get('worker'));
        $this->assertFalse(ProcessCollector::isEmpty());
    }

    public function testGetReturnsEmptyArrayForUnknownName(): void
    {
        $this->assertSame([], ProcessCollector::get('nonexistent'));
    }

    public function testAddMultipleProcessesUnderSameName(): void
    {
        $process1 = Double::for(Process::class)->strict();
        $process2 = Double::for(Process::class)->strict();

        ProcessCollector::add('worker', $process1);
        ProcessCollector::add('worker', $process2);

        $this->assertCount(2, ProcessCollector::get('worker'));
        $this->assertSame($process1, ProcessCollector::get('worker')[0]);
        $this->assertSame($process2, ProcessCollector::get('worker')[1]);
    }

    public function testAddProcessesUnderDifferentNames(): void
    {
        $process1 = Double::for(Process::class)->strict();
        $process2 = Double::for(Process::class)->strict();

        ProcessCollector::add('queue', $process1);
        ProcessCollector::add('scheduler', $process2);

        $this->assertSame([$process1], ProcessCollector::get('queue'));
        $this->assertSame([$process2], ProcessCollector::get('scheduler'));
    }

    public function testAllReturnsFlattenedArray(): void
    {
        $process1 = Double::for(Process::class)->strict();
        $process2 = Double::for(Process::class)->strict();
        $process3 = Double::for(Process::class)->strict();

        ProcessCollector::add('queue', $process1);
        ProcessCollector::add('queue', $process2);
        ProcessCollector::add('scheduler', $process3);

        $all = ProcessCollector::all();
        $this->assertCount(3, $all);
        $this->assertSame($process1, $all[0]);
        $this->assertSame($process2, $all[1]);
        $this->assertSame($process3, $all[2]);
    }

    public function testAllReturnsEmptyArrayWhenEmpty(): void
    {
        $this->assertSame([], ProcessCollector::all());
    }
}
