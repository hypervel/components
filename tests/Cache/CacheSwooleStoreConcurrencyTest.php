<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Cache\SwooleTableState;
use Hypervel\Contracts\Container\Container;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;
use Swoole\Atomic;
use Swoole\Process;
use Throwable;

class CacheSwooleStoreConcurrencyTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testConcurrentAddHasExactlyOneWinner(): void
    {
        $state = $this->createState();

        $results = $this->runConcurrentProcesses($state, 16, function (int $id, SwooleStore $store): array {
            return [
                'id' => $id,
                'won' => $store->add('key', $id, 60),
            ];
        });

        $winners = array_values(array_filter($results, fn (array $result): bool => $result['won']));

        $this->assertCount(1, $winners);
        $this->assertSame($winners[0]['id'], $this->createStore($state)->get('key'));
    }

    public function testConcurrentAddForExpiredPhysicalRowHasExactlyOneWinner(): void
    {
        $state = $this->createState();
        $store = $this->createStore($state);

        $state->table()->set($this->tableKey($store, 'userKey', 'key'), [
            'value' => serialize('expired'),
            'expiration' => time() - 100,
        ]);

        $results = $this->runConcurrentProcesses($state, 16, function (int $id, SwooleStore $store): array {
            return [
                'id' => $id,
                'won' => $store->add('key', $id, 60),
            ];
        });

        $winners = array_values(array_filter($results, fn (array $result): bool => $result['won']));

        $this->assertCount(1, $winners);
        $this->assertSame($winners[0]['id'], $store->get('key'));
    }

    public function testConcurrentIncrementDoesNotLoseUpdates(): void
    {
        $state = $this->createState();
        $processes = 8;
        $incrementsPerProcess = 100;

        $this->runConcurrentProcesses(
            $state,
            $processes,
            function (int $id, SwooleStore $store) use ($incrementsPerProcess): bool {
                for ($i = 0; $i < $incrementsPerProcess; ++$i) {
                    $store->increment('counter');
                }

                return true;
            }
        );

        $this->assertSame($processes * $incrementsPerProcess, $this->createStore($state)->get('counter'));
    }

    public function testConcurrentLockAcquireHasExactlyOneWinner(): void
    {
        $state = $this->createState();

        $results = $this->runConcurrentProcesses($state, 16, function (int $id, SwooleStore $store): array {
            return [
                'id' => $id,
                'won' => $store->lock('lock', 60, "owner-{$id}")->acquire(),
            ];
        });

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['won']));
    }

    /**
     * Run callbacks in forked processes sharing one pre-created Swoole table state.
     */
    private function runConcurrentProcesses(SwooleTableState $state, int $count, callable $callback): array
    {
        $ready = new Atomic(0);
        $start = new Atomic(0);
        $processes = [];

        for ($i = 0; $i < $count; ++$i) {
            $processes[] = new Process(function (Process $process) use ($state, $ready, $start, $callback, $i): void {
                $store = $this->createStore($state);

                $ready->add(1);

                while ($start->get() === 0) {
                    usleep(100);
                }

                try {
                    $process->write(serialize([
                        'ok' => true,
                        'result' => $callback($i, $store, $state),
                    ]));
                } catch (Throwable $exception) {
                    $process->write(serialize([
                        'ok' => false,
                        'error' => $exception::class . ': ' . $exception->getMessage(),
                    ]));
                }

                // The fork inherits PHPUnit/Testbench shutdown handlers from the parent process.
                // Exiting normally would let a child delete the parent's disposable runtime app.
                posix_kill(getmypid(), SIGKILL);
            }, false, SOCK_STREAM);
        }

        foreach ($processes as $process) {
            $process->start();
        }

        $this->waitForReadyProcesses($ready, $count);

        $start->set(1);

        $results = [];

        foreach ($processes as $process) {
            $message = $process->read();

            $this->assertIsString($message);

            $payload = unserialize($message);

            $this->assertIsArray($payload);
            $this->assertTrue($payload['ok'], $payload['error'] ?? 'Child process failed.');

            $results[] = $payload['result'];
        }

        for ($i = 0; $i < $count; ++$i) {
            Process::wait();
        }

        return $results;
    }

    /**
     * Wait until every child process reaches the start barrier.
     */
    private function waitForReadyProcesses(Atomic $ready, int $count): void
    {
        $deadline = microtime(true) + 5;

        while ($ready->get() < $count) {
            if (microtime(true) > $deadline) {
                $this->fail("Only {$ready->get()} of {$count} child processes reached the start barrier.");
            }

            usleep(100);
        }
    }

    private function createState(): SwooleTableState
    {
        return (new SwooleTableManager(m::mock(Container::class)))
            ->createState(128, 10240, 0.2, 12345);
    }

    private function createStore(SwooleTableState $state): SwooleStore
    {
        return new SwooleStore($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
    }

    private function tableKey(SwooleStore $store, string $method, string $key): string
    {
        $reflection = new ReflectionMethod($store, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($store, $key);
    }
}
