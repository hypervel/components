<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Exception;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Throwable;

class DatabaseTransactionsTest extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Setup the database schema.
     */
    protected function setUp(): void
    {
        $db = new DB();

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'second_connection');

        $db->setAsGlobal();

        $this->createSchema();
    }

    protected function createSchema(): void
    {
        foreach (['default', 'second_connection'] as $connection) {
            $this->schema($connection)->create('users', function ($table) {
                $table->increments('id');
                $table->string('name')->nullable();
                $table->string('value')->nullable();
            });
        }
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        foreach (['default', 'second_connection'] as $connection) {
            $this->schema($connection)->drop('users');
        }

        parent::tearDown();
    }

    public function testTransactionIsRecordedAndCommitted()
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager());
        $transactionManager->shouldReceive('begin')->once()->with('default', 1);
        $transactionManager->shouldReceive('commit')->once()->with('default', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->transaction(function () {
            $this->connection()->table('users')->where(['name' => 'zain'])->update([
                'value' => 2,
            ]);
        });
    }

    public function testTransactionIsRecordedAndCommittedUsingTheSeparateMethods()
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager());
        $transactionManager->shouldReceive('begin')->once()->with('default', 1);
        $transactionManager->shouldReceive('commit')->once()->with('default', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->beginTransaction();
        $this->connection()->table('users')->where(['name' => 'zain'])->update([
            'value' => 2,
        ]);
        $this->connection()->commit();
    }

    public function testNestedTransactionIsRecordedAndCommitted()
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager());
        $transactionManager->shouldReceive('begin')->once()->with('default', 1);
        $transactionManager->shouldReceive('begin')->once()->with('default', 2);
        $transactionManager->shouldReceive('commit')->once()->with('default', 2, 1);
        $transactionManager->shouldReceive('commit')->once()->with('default', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->transaction(function () {
            $this->connection()->table('users')->where(['name' => 'zain'])->update([
                'value' => 2,
            ]);

            $this->connection()->transaction(function () {
                $this->connection()->table('users')->where(['name' => 'zain'])->update([
                    'value' => 2,
                ]);
            });
        });
    }

    public function testNestedTransactionIsRecordeForDifferentConnectionsdAndCommitted()
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager());
        $transactionManager->shouldReceive('begin')->once()->with('default', 1);
        $transactionManager->shouldReceive('begin')->once()->with('second_connection', 1);
        $transactionManager->shouldReceive('begin')->once()->with('second_connection', 2);
        $transactionManager->shouldReceive('commit')->once()->with('default', 1, 0);
        $transactionManager->shouldReceive('commit')->once()->with('second_connection', 2, 1);
        $transactionManager->shouldReceive('commit')->once()->with('second_connection', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);
        $this->connection('second_connection')->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->transaction(function () {
            $this->connection()->table('users')->where(['name' => 'zain'])->update([
                'value' => 2,
            ]);

            $this->connection('second_connection')->transaction(function () {
                $this->connection('second_connection')->table('users')->where(['name' => 'zain'])->update([
                    'value' => 2,
                ]);

                $this->connection('second_connection')->transaction(function () {
                    $this->connection('second_connection')->table('users')->where(['name' => 'zain'])->update([
                        'value' => 2,
                    ]);
                });
            });
        });
    }

    public function testTransactionIsRolledBack()
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager());
        $transactionManager->shouldReceive('begin')->once()->with('default', 1);
        $transactionManager->shouldReceive('rollback')->once()->with('default', 0);
        $transactionManager->shouldNotReceive('commit');

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        try {
            $this->connection()->transaction(function () {
                $this->connection()->table('users')->where(['name' => 'zain'])->update([
                    'value' => 2,
                ]);

                throw new Exception();
            });
        } catch (Throwable) {
        }
    }

    public function testTransactionIsRolledBackUsingSeparateMethods()
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager());
        $transactionManager->shouldReceive('begin')->once()->with('default', 1);
        $transactionManager->shouldReceive('rollback')->once()->with('default', 0);
        $transactionManager->shouldNotReceive('commit', 1, 0);

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        $this->connection()->beginTransaction();

        $this->connection()->table('users')->where(['name' => 'zain'])->update([
            'value' => 2,
        ]);

        $this->connection()->rollBack();
    }

    public function testNestedTransactionsAreRolledBack()
    {
        $transactionManager = m::mock(new DatabaseTransactionsManager());
        $transactionManager->shouldReceive('begin')->once()->with('default', 1);
        $transactionManager->shouldReceive('begin')->once()->with('default', 2);
        $transactionManager->shouldReceive('rollback')->once()->with('default', 1);
        $transactionManager->shouldReceive('rollback')->once()->with('default', 0);
        $transactionManager->shouldNotReceive('commit');

        $this->connection()->setTransactionManager($transactionManager);

        $this->connection()->table('users')->insert([
            'name' => 'zain', 'value' => 1,
        ]);

        try {
            $this->connection()->transaction(function () {
                $this->connection()->table('users')->where(['name' => 'zain'])->update([
                    'value' => 2,
                ]);

                $this->connection()->transaction(function () {
                    $this->connection()->table('users')->where(['name' => 'zain'])->update([
                        'value' => 2,
                    ]);

                    throw new Exception();
                });
            });
        } catch (Throwable) {
        }
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(string $connection = 'default'): \Hypervel\Database\Schema\Builder
    {
        return $this->connection($connection)->getSchemaBuilder();
    }

    public function connection(string $name = 'default'): \Hypervel\Database\Connection
    {
        return DB::connection($name);
    }
}
