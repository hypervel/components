<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data;

use Closure;
use Hypervel\Concurrency\ConcurrencyManager;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Support\Facades\Concurrency;
use Hypervel\Support\Facades\DB;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

class CoroutinesInTransactions
{
    public function spawnsInTransaction(Concurrent $concurrent, WaitConcurrent $waitConcurrent, ConcurrencyManager $manager): void
    {
        DB::transaction(function () use ($concurrent, $waitConcurrent, $manager): void {
            Coroutine::create(fn () => null);
            go(fn () => null);
            $concurrent->fork(fn () => null);
            $waitConcurrent->fork(fn () => null);
            Concurrency::run([fn () => 1]);
            $manager->run([fn () => 1]);
            parallel([]);
            Concurrency::run(tasks: []);
            $start = Coroutine::create(...);
        });
    }

    public function spawnsWhileDeferring(): void
    {
        DB::transaction(function (): void {
            DB::afterCommit(function (): void {
                Coroutine::create(fn () => null);
            });
            DB::afterCommit($this->callbackFor(Coroutine::create(fn () => null)));
        });
    }

    public function spawnsInChild(): void
    {
        DB::transaction(function (): void {
            Coroutine::create(function (): void {
                Coroutine::create(fn () => null);
            });
            Coroutine::create(
                $this->callbackFor(Coroutine::create(fn () => null)),
            );
        });
    }

    public function spawnsOutsideTransaction(): void
    {
        Coroutine::create(fn () => null);
    }

    public function spawnsAfterInvalidation(TrackedOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $order->status = 'paid';
            $order->save();
            Coroutine::create(fn () => null);
        });
    }

    public function spawnsInTasks(ConcurrencyManager $manager): void
    {
        DB::transaction(function () use ($manager): void {
            $manager->run([function (): void {
                Coroutine::create(fn () => null);
                array_map(fn (int $value): int => Coroutine::create(fn () => null), [1]);
            }]);
            Concurrency::driver('sync')->run([function (): void {
                Coroutine::create(fn () => null);
            }]);
        });
    }

    public function runsSelectedDrivers(ConcurrencyManager $manager, string $name): void
    {
        DB::transaction(function () use ($manager, $name): void {
            Concurrency::driver('coroutine')->run([function (): void {
                Coroutine::create(fn () => null);
            }]);
            $manager->driver('coroutine')->run([fn () => 1]);
            Concurrency::driver($name)->run([function (): void {
                Coroutine::create(fn () => null);
            }]);
            Concurrency::driver('process')->run([function (): void {
                Coroutine::create(fn () => null);
            }]);
            Concurrency::driver('process')->run([$this->callbackFor(Coroutine::create(fn () => null))]);
        });
    }

    /**
     * @return Closure(): void
     */
    private function callbackFor(int $coroutineId): Closure
    {
        return function (): void {
        };
    }
}

class TrackedOrder
{
    public string $status = 'new';

    public function save(): void
    {
    }
}
