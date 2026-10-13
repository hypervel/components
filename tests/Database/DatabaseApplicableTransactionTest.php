<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Engine\Channel;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use UnitEnum;

use function Hypervel\Coroutine\parallel;

class DatabaseApplicableTransactionTest extends TestCase
{
    /**
     * Configure two isolated databases with one slot each.
     */
    protected function defineEnvironment(Application $app): void
    {
        foreach (['first', 'second'] as $name) {
            $app->make('config')->set('database.connections.' . $name, [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
                'pool' => [
                    'testing_enabled' => true,
                    'max_connections' => 1,
                    'wait_timeout' => 0.1,
                ],
            ]);
        }

        $app->make('config')->set('database.default', 'first');
    }

    public function testCheckingAnUnheldConnectionDoesNotWaitForItsSaturatedPool(): void
    {
        $ready = new Channel(1);
        $release = new Channel(1);

        parallel([
            static function () use ($ready, $release): void {
                $connection = DB::connection('first');
                $connection->beginTransaction();

                try {
                    $ready->push(true);
                    $release->pop(2);
                } finally {
                    $connection->rollBack();
                }
            },
            function () use ($ready, $release): void {
                $ready->pop(2);

                try {
                    $this->assertFalse(DB::hasApplicableTransaction('first'));
                    $this->assertFalse((new ApplicableTransactionModel)->setConnection('first')->hasApplicableTransaction());
                } finally {
                    $release->push(true);
                }
            },
        ]);
    }

    public function testCurrentDefaultAndSharedSqliteRoleAliasesUseTheirHeldConnection(): void
    {
        $connection = DB::connection('first::write');
        $connection->beginTransaction();

        try {
            $model = new ApplicableTransactionModel;
            $this->assertTrue($model->hasApplicableTransaction());
            $this->assertTrue(DB::hasApplicableTransaction('first'));
            $this->assertTrue(DB::hasApplicableTransaction('first::read'));
            $this->assertTrue(DB::hasApplicableTransaction('first::write'));
            $this->assertFalse(DB::hasApplicableTransaction('second'));

            DB::usingConnection('second', function () use ($model): void {
                $this->assertFalse($model->hasApplicableTransaction());
                $this->assertFalse(DB::hasApplicableTransaction());
            });

            $this->assertTrue($model->hasApplicableTransaction());
        } finally {
            $connection->rollBack();
        }

        $this->assertFalse(DB::hasApplicableTransaction());
    }

    public function testCustomManagerResolversProvideTransactionStateAndDateFormat(): void
    {
        $connection = DB::connection('first');
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with('first')->times(3)->andReturn($connection);
        $this->app->instance('db.resolver', $resolver);

        $this->assertFalse(DB::hasApplicableTransaction());
        $this->assertSame($connection->getQueryGrammar()->getDateFormat(), DB::connectionDateFormat('first'));
        $connection->beginTransaction();

        try {
            $this->assertTrue(DB::hasApplicableTransaction('first'));
        } finally {
            $connection->rollBack();
        }
    }

    public function testModelConnectionOverridesAreHonored(): void
    {
        $connection = DB::connection('second');
        $connection->beginTransaction();

        try {
            $this->assertFalse(DB::hasApplicableTransaction('first'));
            $this->assertTrue((new ApplicableTransactionCustomConnectionModel)->hasApplicableTransaction());
            $this->assertTrue((new ApplicableTransactionCustomResolutionModel)->hasApplicableTransaction());
        } finally {
            $connection->rollBack();
        }
    }

    public function testCustomModelResolversUseTheirActualConnection(): void
    {
        $connection = DB::connection('second');
        $connection->beginTransaction();
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->once()->andReturn($connection);
        Model::setConnectionResolver($resolver);

        try {
            $this->assertTrue((new ApplicableTransactionModel)->hasApplicableTransaction());
        } finally {
            $connection->rollBack();
        }
    }
}

class ApplicableTransactionModel extends Model
{
}

class ApplicableTransactionCustomConnectionModel extends Model
{
    /**
     * Resolve this model on its custom connection.
     */
    public function getConnection(): Connection
    {
        return DB::connection('second');
    }
}

class ApplicableTransactionCustomResolutionModel extends Model
{
    /**
     * Resolve this model through its custom static resolver.
     */
    public static function resolveConnection(UnitEnum|string|null $connection = null): Connection
    {
        return DB::connection('second');
    }
}
