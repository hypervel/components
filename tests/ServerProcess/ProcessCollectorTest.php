<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\ServerProcess\ProcessCollector;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use Swoole\Process;

/**
 * @internal
 * @coversNothing
 */
class ProcessCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset static state via reflection
        $ref = new ReflectionClass(ProcessCollector::class);
        $prop = $ref->getProperty('processes');
        $prop->setValue(null, []);
    }

    public function testIsEmptyInitially()
    {
        $this->assertTrue(ProcessCollector::isEmpty());
    }

    public function testAddAndGetByName()
    {
        $process = new Process(function () {});

        ProcessCollector::add('worker', $process);

        $this->assertSame([$process], ProcessCollector::get('worker'));
        $this->assertFalse(ProcessCollector::isEmpty());
    }

    public function testGetReturnsEmptyArrayForUnknownName()
    {
        $this->assertSame([], ProcessCollector::get('nonexistent'));
    }

    public function testAddMultipleProcessesUnderSameName()
    {
        $process1 = new Process(function () {});
        $process2 = new Process(function () {});

        ProcessCollector::add('worker', $process1);
        ProcessCollector::add('worker', $process2);

        $this->assertCount(2, ProcessCollector::get('worker'));
        $this->assertSame($process1, ProcessCollector::get('worker')[0]);
        $this->assertSame($process2, ProcessCollector::get('worker')[1]);
    }

    public function testAddProcessesUnderDifferentNames()
    {
        $process1 = new Process(function () {});
        $process2 = new Process(function () {});

        ProcessCollector::add('queue', $process1);
        ProcessCollector::add('scheduler', $process2);

        $this->assertSame([$process1], ProcessCollector::get('queue'));
        $this->assertSame([$process2], ProcessCollector::get('scheduler'));
    }

    public function testAllReturnsFlattenedArray()
    {
        $process1 = new Process(function () {});
        $process2 = new Process(function () {});
        $process3 = new Process(function () {});

        ProcessCollector::add('queue', $process1);
        ProcessCollector::add('queue', $process2);
        ProcessCollector::add('scheduler', $process3);

        $all = ProcessCollector::all();
        $this->assertCount(3, $all);
        $this->assertSame($process1, $all[0]);
        $this->assertSame($process2, $all[1]);
        $this->assertSame($process3, $all[2]);
    }

    public function testAllReturnsEmptyArrayWhenEmpty()
    {
        $this->assertSame([], ProcessCollector::all());
    }
}
