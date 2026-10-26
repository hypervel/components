<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connection;
use Hypervel\Database\DeadlockException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

#[RequiresDatabase(['mysql', 'mariadb', 'pgsql'])]
#[ResetRefreshDatabaseState]
class NestedTransactionRetryTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Give concurrent transactions independent pooled sessions.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $connection = $config->string('database.default');
        $config->set("database.connections.{$connection}.pool.testing_enabled", true);
    }

    /**
     * Create the rows used to exercise transaction ownership.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('retry_records', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->integer('value');
        });
        Schema::create('retry_attempts', static function (Blueprint $table): void {
            $table->unsignedInteger('worker')->primary();
            $table->unsignedInteger('attempt');
        });
    }

    #[RequiresDatabase('pgsql')]
    public function testCaughtNestedLockFailureRollsBackOnlyNestedWork(): void
    {
        DB::table('retry_records')->insert(['id' => 0, 'value' => 0]);
        $ready = new Channel(1);
        $release = new Channel(1);
        $committed = [];

        parallel([
            fn () => DB::transaction(function () use ($ready, $release): void {
                DB::table('retry_records')->where('id', 0)->lockForUpdate()->first();
                $ready->push(true);
                $this->assertTrue($release->pop(5));
            }),
            function () use ($ready, $release, &$committed): void {
                try {
                    $this->assertTrue($ready->pop(5));
                    DB::transaction(function (Connection $connection) use (&$committed): void {
                        $connection->table('retry_records')->insert(['id' => 1, 'value' => 1]);
                        $connection->afterCommit(static function () use (&$committed): void {
                            $committed[] = 'outer';
                        });

                        try {
                            $connection->transaction(function () use ($connection, &$committed): void {
                                $connection->table('retry_records')->insert(['id' => 2, 'value' => 2]);
                                $connection->afterCommit(static function () use (&$committed): void {
                                    $committed[] = 'nested';
                                });
                                $connection->table('retry_records')->where('id', 0)->lock('for update nowait')->first();
                            });
                            $this->fail('The competing transaction must hold the row lock.');
                        } catch (DeadlockException $exception) {
                            $this->assertSame('55P03', $exception->getCode());
                        }
                    });
                } finally {
                    $release->push(true);
                }
            },
        ]);

        $this->assertSame([0, 1], DB::table('retry_records')->orderBy('id')->pluck('id')->all());
        $this->assertSame(['outer'], $committed);
    }

    public function testOnlyTheOuterTransactionRetriesAfterAManualSavepointRollback(): void
    {
        DB::table('retry_records')->insert([['id' => 0, 'value' => 0], ['id' => 1, 'value' => 0]]);
        $ready = new Channel(2);
        $release = new Channel(2);
        $finished = new Channel(2);
        $outerAttempts = $innerAttempts = [0, 0];
        $committed = [];
        $work = static function (int $worker) use ($ready, $release, $finished, &$outerAttempts, &$innerAttempts, &$committed): array {
            $connection = DB::connection();
            $connection->transaction(static function (Connection $connection) use ($worker, $ready, $release, $finished, &$outerAttempts, &$innerAttempts, &$committed): void {
                $attempt = ++$outerAttempts[$worker];

                // Let the surviving transaction commit before retrying, so the test causes exactly one deadlock.
                if ($attempt > 1 && $finished->pop(5) !== true) {
                    throw new RuntimeException('The competing transaction did not finish before the retry.');
                }

                $connection->table('retry_attempts')->insert(['worker' => $worker, 'attempt' => $attempt]);
                $connection->afterCommit(static function () use ($worker, $attempt, &$committed): void {
                    $committed[$worker][] = $attempt;
                });

                $connection->transaction(static function (Connection $connection) use ($worker, $attempt, $ready, $release, &$innerAttempts): void {
                    ++$innerAttempts[$worker];
                    $level = $connection->transactionLevel();
                    $connection->beginTransaction();

                    try {
                        $connection->table('retry_records')->where('id', $worker)->increment('value');

                        if ($attempt === 1) {
                            $ready->push(true);

                            if ($release->pop(5) !== true) {
                                throw new RuntimeException('Concurrent transaction coordination timed out.');
                            }
                        }

                        $connection->table('retry_records')->where('id', 1 - $worker)->increment('value');
                        $connection->commit();
                    } catch (Throwable $failure) {
                        try {
                            $connection->rollBack($level);
                        } catch (CanceledException $cancellation) {
                            throw $cancellation;
                        } catch (Throwable) {
                            // Cleanup must not replace the operation's deadlock exception.
                        }

                        throw $failure;
                    }
                }, 3);
            }, 3);

            $finished->push(true);

            return [$connection->transactionLevel(), $connection->isReusable()];
        };

        [$first, $second] = parallel([
            static fn (): array => $work(0),
            static fn (): array => $work(1),
            function () use ($ready, $release): void {
                try {
                    $this->assertTrue($ready->pop(5));
                    $this->assertTrue($ready->pop(5));
                } finally {
                    $release->push(true);
                    $release->push(true);
                }
            },
        ]);

        $this->assertSame([0, true], $first);
        $this->assertSame([0, true], $second);
        $this->assertSame(3, array_sum($outerAttempts));
        $this->assertSame($outerAttempts, $innerAttempts);
        $this->assertSame([2, 2], DB::table('retry_records')->orderBy('id')->pluck('value')->all());
        $this->assertSame($outerAttempts, DB::table('retry_attempts')->orderBy('worker')->pluck('attempt')->all());
        $this->assertSame([$outerAttempts[0]], $committed[0]);
        $this->assertSame([$outerAttempts[1]], $committed[1]);
    }
}
