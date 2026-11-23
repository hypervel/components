<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentBuilderCreateOrFirstTest;

use ArrayObject;
use Closure;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Events\Dispatcher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseEloquentBuilderCreateOrFirstTest extends TestCase
{
    /**
     * Set up independent writer and replica databases.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2023-01-01 00:00:00');

        $db = new DB;

        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);

        // Reads come from a replica that has not caught up with the writer yet...
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => ['database' => ':memory:'],
            'write' => ['database' => ':memory:'],
        ], 'replicated');

        $db->setEventDispatcher(new Dispatcher);
        $db->bootEloquent();
        $db->setAsGlobal();

        $schema = 'create table "records" (
            "id" integer primary key autoincrement not null,
            "attr" varchar not null unique,
            "val" varchar null,
            "count" integer not null default 0,
            "created_at" datetime null,
            "updated_at" datetime null
        )';

        $db->getConnection()->getPdo()->exec($schema);
        $db->getConnection('replicated')->getPdo()->exec($schema);
        $db->getConnection('replicated')->getReadPdo()->exec($schema);
    }

    #[DataProvider('createOrFirstValues')]
    public function testCreateOrFirstMethodCreatesNewRecord(Closure|array $values): void
    {
        $result = $this->modelQuery()->createOrFirst(['attr' => 'foo'], $values);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame(1, $this->rows());
    }

    public function testCreateOrFirstMethodRetrievesExistingRecord(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar']);

        $result = $this->modelQuery()->createOrFirst(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('bar', $result->val);
        $this->assertSame(1, $this->rows());
    }

    public function testFirstOrCreateMethodRetrievesExistingRecord(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar']);

        $result = $this->modelQuery()->firstOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('bar', $result->val);
        $this->assertSame(1, $this->rows());
    }

    public function testFirstOrCreateMethodCreatesNewRecord(): void
    {
        $result = $this->modelQuery()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame(1, $this->rows());
    }

    public function testFirstOrCreateMethodRetrievesRecordCreatedJustNow(): void
    {
        // The replica has not seen the record yet, so the insert collides and the writer is asked again...
        $this->seedWriter(['attr' => 'foo', 'val' => 'bar']);

        $result = $this->modelQuery('replicated')->firstOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('bar', $result->val);
        $this->assertSame(1, $this->rows('replicated'));
    }

    public function testFirstOrCreateDoesNotTouchTheWriterWhenTheReplicaHasTheRecord(): void
    {
        $this->seedReplica(['attr' => 'foo', 'val' => 'bar']);
        $queries = $this->recordQueries('replicated');

        $result = $this->modelQuery('replicated')->firstOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame('bar', $result->val);
        $this->assertNotContains('write', array_column($queries->getArrayCopy(), 1));
    }

    public function testUpdateOrCreateLeavesOtherColumnsAndCreatedAtAlone(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar', 'count' => 5, 'created_at' => '2022-12-31 00:00:00', 'updated_at' => '2022-12-31 00:00:00']);

        $this->modelQuery()->updateOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $record = (array) DB::connection()->table('records')->first();
        $this->assertSame('baz', $record['val']);
        $this->assertEquals(5, $record['count']);
        $this->assertSame('2022-12-31 00:00:00', $record['created_at']);
        $this->assertSame('2023-01-01 00:00:00', $record['updated_at']);
    }

    public function testUpdateOrCreateMethodUpdatesExistingRecord(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar']);

        $result = $this->modelQuery()->updateOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('baz', $result->val);
        $this->assertSame('baz', $this->fresh()->val);
        $this->assertSame(1, $this->rows());
    }

    public function testUpdateOrCreateMethodCreatesNewRecord(): void
    {
        $result = $this->modelQuery()->updateOrCreate(['attr' => 'foo'], ['val' => 'bar']);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame(1, $this->rows());
    }

    public function testUpdateOrCreateMethodUpdatesRecordCreatedJustNow(): void
    {
        $this->seedWriter(['attr' => 'foo', 'val' => 'bar']);

        $result = $this->modelQuery('replicated')->updateOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('baz', $result->val);
        $this->assertSame('baz', $this->fresh('replicated', onWriter: true)->val);
        $this->assertSame(1, $this->rows('replicated'));
    }

    public function testIncrementOrCreateMethodIncrementsExistingRecord(): void
    {
        $this->seedRecord(['attr' => 'foo', 'count' => 1]);

        $result = $this->modelQuery()->incrementOrCreate(['attr' => 'foo'], 'count');

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertEquals(2, $result->count);
        $this->assertEquals(2, $this->fresh()->count);
    }

    public function testIncrementOrCreateMethodCreatesNewRecord(): void
    {
        $result = $this->modelQuery()->incrementOrCreate(['attr' => 'foo']);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'count' => 1,
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertEquals(1, $this->fresh()->count);
    }

    public function testIncrementOrCreateMethodIncrementParametersArePassed(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar', 'count' => 1]);

        $result = $this->modelQuery()->incrementOrCreate(['attr' => 'foo'], step: 2, extra: ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals(3, $result->count);
        $this->assertSame('baz', $result->val);
        $this->assertEquals(3, $this->fresh()->count);
        $this->assertSame('baz', $this->fresh()->val);
    }

    public function testIncrementOrCreateMethodExtraParametersArePassedWhenCreating(): void
    {
        $result = $this->modelQuery()->incrementOrCreate(['attr' => 'foo'], step: 2, extra: ['val' => 'baz']);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertSame('baz', $result->val);
        $this->assertSame(1, $result->count);
        $this->assertEquals(1, $this->fresh()->count);
        $this->assertSame('baz', $this->fresh()->val);
    }

    public function testIncrementOrCreateMethodRetrievesRecordCreatedJustNow(): void
    {
        $this->seedWriter(['attr' => 'foo', 'count' => 1]);

        $result = $this->modelQuery('replicated')->incrementOrCreate(['attr' => 'foo']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertEquals(2, $result->count);
        $this->assertEquals(2, $this->fresh('replicated', onWriter: true)->count);
    }

    #[DataProvider('createOrFirstValues')]
    public function testUpdateOrCreateMethodAcceptsClosureValuesAndCreates(Closure|array $values): void
    {
        $result = $this->modelQuery()->updateOrCreate(['attr' => 'foo'], $values);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertSame('foo', $result->attr);
        $this->assertSame('bar', $result->val);
        $this->assertSame('bar', $this->fresh()->val);
    }

    public function testUpdateOrCreateMethodAcceptsClosureValuesAndUpdates(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar']);

        $result = $this->modelQuery()->updateOrCreate(['attr' => 'foo'], fn () => ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame('baz', $result->val);
        $this->assertSame('baz', $this->fresh()->val);
    }

    public function testUpdateOrCreateInvokesClosureExactlyOnceWhenCreating(): void
    {
        $callCount = 0;

        $result = $this->modelQuery()->updateOrCreate(['attr' => 'foo'], function () use (&$callCount) {
            ++$callCount;

            return ['val' => 'bar'];
        });

        $this->assertSame(1, $callCount);
        $this->assertSame('bar', $result->val);
    }

    public function testUpdateOrCreateInvokesClosureExactlyOnceWhenUpdating(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar']);

        $callCount = 0;

        $result = $this->modelQuery()->updateOrCreate(['attr' => 'foo'], function () use (&$callCount) {
            ++$callCount;

            return ['val' => 'baz'];
        });

        $this->assertSame(1, $callCount);
        $this->assertSame('baz', $result->val);
    }

    #[DataProvider('createOrFirstValues')]
    public function testFirstOrNewMethodAcceptsClosureValuesAndInstantiates(Closure|array $values): void
    {
        $result = $this->modelQuery()->firstOrNew(['attr' => 'foo'], $values);

        $this->assertFalse($result->exists);
        $this->assertSame('foo', $result->attr);
        $this->assertSame('bar', $result->val);
        $this->assertSame(0, $this->rows());
    }

    /**
     * Provide array and closure creation values.
     */
    public static function createOrFirstValues(): array
    {
        return [
            'array' => [['val' => 'bar']],
            'closure' => [fn () => ['val' => 'bar']],
        ];
    }

    public function testFirstOrNewDoesNotInvokeClosureWhenRecordExists(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar']);

        $callCount = 0;
        $result = $this->modelQuery()->firstOrNew(['attr' => 'foo'], function () use (&$callCount) {
            ++$callCount;

            return ['val' => 'should-not-be-called'];
        });

        $this->assertSame(0, $callCount);
        $this->assertTrue($result->exists);
        $this->assertSame('bar', $result->val);
    }

    /**
     * Create a model query on the given connection.
     */
    protected function modelQuery(string $connection = 'default'): Builder
    {
        return (new TestModel)->setConnection($connection)->newQuery();
    }

    /**
     * Insert a record on the default connection.
     */
    protected function seedRecord(array $attributes): void
    {
        $this->seedWriter($attributes, 'default');
    }

    /**
     * Insert a record on the writer.
     */
    protected function seedWriter(array $attributes, string $connection = 'replicated'): void
    {
        DB::connection($connection)->table('records')->insert($attributes + [
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]);
    }

    /**
     * Insert a record directly on the replica.
     */
    protected function seedReplica(array $attributes): void
    {
        DB::connection('replicated')->getReadPdo()->exec(sprintf(
            'insert into "records" ("attr", "val", "created_at", "updated_at") values (%s)',
            implode(', ', array_map(fn ($value) => "'{$value}'", [$attributes['attr'], $attributes['val'], '2023-01-01 00:00:00', '2023-01-01 00:00:00']))
        ));
    }

    /**
     * Record the verb and read/write side of each query the connection reports.
     */
    protected function recordQueries(string $connection): ArrayObject
    {
        $queries = new ArrayObject;

        DB::connection($connection)->listen(function (QueryExecuted $query) use ($queries) {
            $queries[] = [strtok($query->sql, ' '), $query->readWriteType];
        });

        return $queries;
    }

    /**
     * Retrieve the stored record from the selected connection role.
     */
    protected function fresh(string $connection = 'default', bool $onWriter = false): TestModel
    {
        $query = $this->modelQuery($connection);

        return ($onWriter ? $query->useWritePdo() : $query)->where('attr', 'foo')->firstOrFail();
    }

    /**
     * Count the records stored on the writer.
     */
    protected function rows(string $connection = 'default'): int
    {
        return DB::connection($connection)->table('records')->useWritePdo()->count();
    }
}

class TestModel extends Model
{
    protected ?string $table = 'records';

    protected array $guarded = [];
}
