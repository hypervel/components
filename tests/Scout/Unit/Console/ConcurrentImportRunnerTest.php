<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Engine\Channel;
use Hypervel\Scout\Console\ConcurrentImportRunner;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ConcurrentImportRunnerTest extends TestCase
{
    public function testWaitDrainsActiveChildrenBeforeRethrowingTheFirstFailure(): void
    {
        $runner = new ConcurrentImportRunner(2);
        $completed = false;
        $failure = new RuntimeException('Import failed.');

        $runner->create(function () use (&$completed): void {
            usleep(10_000);
            $completed = true;
        });

        try {
            $runner->create(function () use ($failure): void {
                throw $failure;
            });
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        try {
            $runner->wait();
            $this->fail('The child import failure was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($completed);
    }

    public function testWaitPreservesTheFirstFailureWhenMultipleChildrenFail(): void
    {
        $runner = new ConcurrentImportRunner(2);
        $releaseFirst = new Channel(1);
        $releaseSecond = new Channel(1);
        $firstFailure = new RuntimeException('First failure.');
        $secondFailure = new RuntimeException('Second failure.');

        $runner->create(function () use ($releaseFirst, $firstFailure): void {
            $releaseFirst->pop();
            throw $firstFailure;
        });

        $runner->create(function () use ($releaseSecond, $secondFailure): void {
            $releaseSecond->pop();
            throw $secondFailure;
        });

        $releaseFirst->push(true);
        usleep(10_000);
        $releaseSecond->push(true);

        try {
            $runner->wait();
            $this->fail('The child import failure was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstFailure, $exception);
        }
    }
}
