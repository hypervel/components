<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Oauth2;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Saloon\Http\Auth\TokenAcquisition;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\go;

class TokenAcquisitionTest extends TestCase
{
    public function testCancelingAWaiterDoesNotReleaseTheOwnersAcquisition(): void
    {
        $acquisition = new TokenAcquisition;
        $release = new Channel(1);
        $results = new Channel(3);
        $owner = go(fn () => $results->push($acquisition->run('grant', function () use ($release): string {
            $release->pop(1);

            return 'owner';
        })));
        $waiter = go(function () use ($acquisition, $results): void {
            try {
                $results->push($acquisition->run('grant', fn (): string => 'wrong waiter'));
            } catch (CanceledException $exception) {
                $results->push($exception);
            }
        });

        try {
            $this->assertTrue(EngineCoroutine::cancelById($waiter));
            $this->assertInstanceOf(CanceledException::class, $results->pop(1));
            $other = go(fn () => $results->push($acquisition->run('grant', fn (): string => 'wrong successor')));
            $this->assertTrue($results->isEmpty());
            $release->push(true);
            $this->assertSame('owner', $results->pop(1));
            $this->assertSame('owner', $results->pop(1));
        } finally {
            $release->close();
            EngineCoroutine::join([$owner, $waiter, ...isset($other) ? [$other] : []], 1);
            $results->close();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCanceledOwnerLeavesOneSuccessorAndDoesNotCancelWaiters(bool $cleanupThrows): void
    {
        $acquisition = new TokenAcquisition;
        $release = new Channel(1);
        $results = new Channel(3);
        $cleanupException = new RuntimeException('Token acquisition cleanup failed.');
        $owner = go(function () use ($acquisition, $release, $results, $cleanupThrows, $cleanupException): void {
            try {
                $acquisition->run('grant', function () use ($release, $cleanupThrows, $cleanupException): void {
                    try {
                        $release->pop(1);

                        if ($release->isCanceled()) {
                            throw new CanceledException('Owner canceled.');
                        }
                    } finally {
                        if ($cleanupThrows) {
                            usleep(1000);

                            throw $cleanupException;
                        }
                    }
                });
            } catch (Throwable $exception) {
                $results->push($exception);
            }
        });
        $calls = 0;
        $resolve = function () use (&$calls): string {
            ++$calls;
            usleep(5000);

            return 'replacement';
        };
        $wait = static function () use ($acquisition, $results, $resolve): void {
            try {
                $results->push($acquisition->run('grant', $resolve));
            } catch (Throwable $exception) {
                $results->push($exception);
            }
        };
        $first = go($wait);
        $second = go($wait);

        try {
            $this->assertTrue(EngineCoroutine::cancelById($owner));
            $ownerException = $results->pop(1);
            $this->assertTrue(Coroutine::isCancellation($ownerException));
            if ($cleanupThrows) {
                $this->assertSame($cleanupException, $ownerException);
                $this->assertInstanceOf(CanceledException::class, $ownerException->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $ownerException);
            }
            $this->assertSame('replacement', $results->pop(1));
            $this->assertSame('replacement', $results->pop(1));
            $this->assertSame(1, $calls);
        } finally {
            $release->close();
            EngineCoroutine::join([$owner, $first, $second], 1);
            $results->close();
        }
    }
}
