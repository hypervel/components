<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;

class SkipLockedTest extends TestCase
{
    use DatabaseTruncation;

    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $default = $config->string('database.default');
        $connection = $config->array("database.connections.{$default}");
        $connection['lock_timeout'] = 1;
        $connection['pool'] = [
            'testing_enabled' => true,
            'min_retained_connections' => 1,
            'max_connections' => 1,
            'heartbeat_interval' => null,
        ];

        $config->set('database.connections.skip_locked', $connection);
    }

    public function testBothLockModesSkipRowsHeldByAnotherConnection(): void
    {
        if (! DB::connection()->supportsSkipLocked()) {
            $this->markTestSkipped('This server does not support skipping locked rows.');
        }

        $holderPool = new DatabasePool($this->app, 'skip_locked');
        $contenderPool = new DatabasePool($this->app, 'skip_locked');
        $holderLease = $holderPool->borrow();

        try {
            $contenderLease = $contenderPool->borrow();

            try {
                $holder = $holderLease->getConnection();
                $contender = $contenderLease->getConnection();
                $schema = $holder->getSchemaBuilder();
                $schema->create('skip_locked_rows', function (Blueprint $table): void {
                    $table->integer('id')->primary();
                });

                try {
                    $holder->table('skip_locked_rows')->insert([['id' => 1], ['id' => 2]]);

                    foreach (['lockForUpdate', 'sharedLock'] as $lockMethod) {
                        $holder->beginTransaction();

                        try {
                            $holder->table('skip_locked_rows')->where('id', 1)->lockForUpdate()->first();

                            $contender->transaction(function () use ($contender, $lockMethod): void {
                                $rows = $contender->table('skip_locked_rows')
                                    ->orderBy('id')->{$lockMethod}()->skipLocked()->pluck('id');

                                $this->assertSame([2], $rows->all());
                            });
                        } finally {
                            $holder->rollBack();
                        }
                    }
                } finally {
                    $schema->drop('skip_locked_rows');
                }
            } finally {
                $contenderLease->release();
            }
        } finally {
            $holderLease->release();
            $holderPool->close();
            $contenderPool->close();
        }
    }
}
