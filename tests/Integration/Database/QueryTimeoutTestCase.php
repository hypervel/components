<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

abstract class QueryTimeoutTestCase extends DatabaseTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $connection = $config->string('database.default');
        $config->set(
            'database.connections.query_timeout_peer',
            $config->array('database.connections.' . $connection),
        );
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('query_timeout_probes', function (Blueprint $table): void {
            $table->increments('id');
        });

        DB::table('query_timeout_probes')->insert(['id' => 1]);
    }

    public function testTimeoutInterruptsOrdinarySelect(): void
    {
        $this->assertQueryTimesOut(
            fn () => DB::table('query_timeout_probes')->selectRaw('SLEEP(2) as delay')->timeout(1)->get()
        );
    }

    public function testTimeoutInterruptsExistsSelect(): void
    {
        $this->assertQueryTimesOut(
            fn () => DB::table('query_timeout_probes')
                ->whereRaw('SLEEP(2) = 0')
                ->timeout(1)
                ->exists()
        );
    }

    public function testTimeoutInterruptsUnionSelect(): void
    {
        $this->assertQueryTimesOut(
            fn () => DB::query()
                ->selectRaw('1 as value')
                ->unionAll(DB::query()->selectRaw('SLEEP(2) as value'))
                ->timeout(1)
                ->get()
        );
    }

    public function testTimeoutInterruptsBlockedLockingSelect(): void
    {
        $owner = DB::connection();
        $contender = DB::connection('query_timeout_peer');
        $originalLockWaitTimeout = $contender->scalar('select @@session.innodb_lock_wait_timeout');

        try {
            $contender->statement('SET SESSION innodb_lock_wait_timeout = 3');
            $owner->beginTransaction();
            $owner->table('query_timeout_probes')->where('id', 1)->lockForUpdate()->first();

            $this->assertQueryTimesOut(
                fn () => $contender->table('query_timeout_probes')
                    ->where('id', 1)
                    ->lockForUpdate()
                    ->timeout(1)
                    ->first()
            );
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }

            $contender->statement(
                'SET SESSION innodb_lock_wait_timeout = ' . (int) $originalLockWaitTimeout
            );
        }
    }

    /**
     * Assert that the database reports its statement-timeout error.
     */
    protected function assertQueryTimesOut(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the database statement timeout to interrupt the query.');
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression($this->timeoutErrorPattern(), $exception->getMessage());
        }
    }

    /**
     * Get the database-specific timeout error pattern.
     */
    abstract protected function timeoutErrorPattern(): string;
}
