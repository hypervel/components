<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Closure;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use Hypervel\Watcher\Driver\AbstractDriver;
use Hypervel\Watcher\Option;
use RuntimeException;

class AbstractDriverTest extends TestCase
{
    public function testPollingScansImmediatelyAndThenWaitsBetweenScans(): void
    {
        $scans = new Channel(2);
        $scanCount = 0;
        $driver = new PollingDriverStub(0.2, function () use ($scans, &$scanCount): void {
            $scans->push(++$scanCount);
        });
        $output = new Channel(1);
        $finished = new WaitGroup(1);

        Coroutine::create(function () use ($driver, $finished, $output): void {
            try {
                $driver->watch($output);
            } finally {
                $finished->done();
            }
        });

        try {
            $this->assertSame(1, $scans->pop(0.05));
            $this->assertFalse($scans->pop(0.05));
            $this->assertSame(2, $scans->pop(0.3));
        } finally {
            $driver->stop();
            $this->assertTrue($finished->wait(0.1));
            $scans->close();
            $output->close();
        }
    }

    public function testStopBeforeWatchPreventsTheInitialScan(): void
    {
        $scanCount = 0;
        $driver = new PollingDriverStub(1.0, function () use (&$scanCount): void {
            ++$scanCount;
        });
        $output = new Channel(1);

        try {
            $driver->stop();
            $driver->stop();
            $driver->watch($output);

            $this->assertSame(0, $scanCount);
            $this->assertFalse($driver->hasActiveStopSignal());
        } finally {
            $output->close();
        }
    }

    public function testStopDuringAYieldingScanPreventsAnotherWaitOrScan(): void
    {
        $entered = new Channel(1);
        $resume = new Channel(1);
        $scanCount = 0;
        $driver = new PollingDriverStub(60.0, function () use ($entered, $resume, &$scanCount): void {
            ++$scanCount;
            $entered->push(true);
            $resume->pop();
        });
        $output = new Channel(1);
        $finished = new WaitGroup(1);

        Coroutine::create(function () use ($driver, $finished, $output): void {
            try {
                $driver->watch($output);
            } finally {
                $finished->done();
            }
        });

        try {
            $this->assertTrue($entered->pop(0.1));
            $driver->stop();
            $resume->push(true);

            $this->assertTrue($finished->wait(0.1));
            $this->assertSame(1, $scanCount);
            $this->assertFalse($driver->hasActiveStopSignal());
        } finally {
            $driver->stop();
            $entered->close();
            $resume->close();
            $output->close();
        }
    }

    public function testStopWhileWaitingEndsTheLifecycleWithoutAnotherScan(): void
    {
        $scanned = new Channel(1);
        $scanCount = 0;
        $driver = new PollingDriverStub(60.0, function () use ($scanned, &$scanCount): void {
            ++$scanCount;
            $scanned->push(true);
        });
        $output = new Channel(1);
        $finished = new WaitGroup(1);

        Coroutine::create(function () use ($driver, $finished, $output): void {
            try {
                $driver->watch($output);
            } finally {
                $finished->done();
            }
        });

        try {
            $this->assertTrue($scanned->pop(0.1));
            $driver->stop();

            $this->assertTrue($finished->wait(0.1));
            $this->assertSame(1, $scanCount);
            $this->assertFalse($driver->hasActiveStopSignal());
        } finally {
            $driver->stop();
            $scanned->close();
            $output->close();
        }
    }

    public function testScanExceptionCleansTheStopSignal(): void
    {
        $exception = new RuntimeException('scan failed');
        $driver = new PollingDriverStub(1.0, function () use ($exception): never {
            throw $exception;
        });
        $output = new Channel(1);
        $caught = null;

        try {
            $driver->watch($output);
        } catch (RuntimeException $caughtException) {
            $caught = $caughtException;
        } finally {
            $output->close();
        }

        $this->assertSame($exception, $caught);
        $this->assertFalse($driver->hasActiveStopSignal());
    }
}

class PollingDriverStub extends AbstractDriver
{
    public function __construct(
        protected float $interval,
        protected Closure $scan,
    ) {
        parent::__construct(new Option);
    }

    public function watch(Channel $channel): void
    {
        $this->watchAtInterval($this->interval, $this->scan);
    }

    public function hasActiveStopSignal(): bool
    {
        return $this->stopSignal !== null;
    }
}
