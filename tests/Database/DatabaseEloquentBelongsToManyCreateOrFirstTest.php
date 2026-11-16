<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentBelongsToManyCreateOrFirstTest;

use Closure;
use Exception;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class DatabaseEloquentBelongsToManyCreateOrFirstTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Set up the database schema.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2023-01-01 00:00:00');

        $db = new DB;

        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->bootEloquent();
        $db->setAsGlobal();

        $pdo = $db->getConnection()->getPdo();

        $pdo->exec('create table "related_table" (
            "id" integer primary key autoincrement not null,
            "attr" varchar not null unique,
            "val" varchar null,
            "created_at" datetime null,
            "updated_at" datetime null
        )');
        $pdo->exec('create table "pivot_table" (
            "source_id" integer not null,
            "related_id" integer not null,
            unique ("source_id", "related_id")
        )');
    }

    #[DataProvider('createOrFirstValues')]
    public function testCreateOrFirstMethodCreatesNewRelated(Closure|array $values): void
    {
        $result = $this->related()->createOrFirst(['attr' => 'foo'], $values);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame([[123, 1]], $this->pivots());
    }

    public static function createOrFirstValues(): array
    {
        return [
            'array' => [['val' => 'bar']],
            'closure' => [fn () => ['val' => 'bar']],
        ];
    }

    public function testCreateOrFirstMethodAssociatesExistingRelated(): void
    {
        $this->seedRelated(['attr' => 'foo', 'val' => 'bar']);

        $result = $this->related()->createOrFirst(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame(1, $this->relatedRows());
        $this->assertSame([[123, 1]], $this->pivots());
    }

    public function testFirstOrCreateMethodRetrievesExistingRelatedAlreadyAssociated(): void
    {
        $this->seedRelated(['attr' => 'foo', 'val' => 'bar']);
        $this->seedPivot(123, 1);

        $result = $this->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
            'pivot' => ['source_id' => 123, 'related_id' => 1],
        ], $result->toArray());
        $this->assertSame([[123, 1]], $this->pivots());
    }

    public function testCreateOrFirstMethodRetrievesExistingRelatedAssociatedJustNow(): void
    {
        // Recovery checks the existing pivot without loading it onto the returned model.
        $this->seedRelated(['attr' => 'foo', 'val' => 'bar']);
        $this->seedPivot(123, 1);

        $result = $this->related()->createOrFirst(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame([[123, 1]], $this->pivots());
    }

    public function testCreateOrFirstMethodReturnsExistingRelatedAfterAttachCollision(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );
        $source->getConnection()->allows('transactionLevel')->returns(0);
        $source->getConnection()->allows('getWritableName')->returns('sqlite');

        $sql = 'insert into "related_table" ("attr", "val", "updated_at", "created_at") values (?, ?, ?, ?)';
        $bindings = ['foo', 'bar', '2023-01-01 00:00:00', '2023-01-01 00:00:00'];

        $source->getConnection()
            ->expects('insert')
            ->with($sql, $bindings)
            ->throws(new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception));

        $source->getConnection()
            ->expects('select')
            ->with('select * from "related_table" where ("attr" = ?) limit 1', ['foo'], false, [])
            ->returns([[
                'id' => 456,
                'attr' => 'foo',
                'val' => 'bar',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => '2023-01-01 00:00:00',
            ]]);

        $sql = 'insert into "pivot_table" ("related_id", "source_id") values (?, ?)';
        $bindings = [456, 123];

        $source->getConnection()
            ->expects('insert')
            ->with($sql, $bindings)
            ->throws(new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception));

        $source->getConnection()
            ->expects('select')
            ->times(2)
            ->with(
                'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
                [123, 456],
                false,
            )
            ->returns([['exists' => 0]], [['exists' => 1]]);

        $result = $source->related()->createOrFirst(['attr' => 'foo'], ['val' => 'bar']);
        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    public function testCreateOrFirstMethodRethrowsAttachViolationWhenExactPivotIsMissing(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels([$source, new RelatedModel], 'SQLite');
        $source->getConnection()->allows('transactionLevel')->returns(0);
        $source->getConnection()->allows('getWritableName')->returns('sqlite');

        $relatedSql = 'insert into "related_table" ("attr", "val", "updated_at", "created_at") values (?, ?, ?, ?)';
        $relatedBindings = ['foo', 'bar', '2023-01-01 00:00:00', '2023-01-01 00:00:00'];

        $source->getConnection()->expects('insert')->with($relatedSql, $relatedBindings)->throws(
            new UniqueConstraintViolationException('sqlite', $relatedSql, $relatedBindings, new Exception)
        );
        $source->getConnection()->expects('select')->with(
            'select * from "related_table" where ("attr" = ?) limit 1',
            ['foo'],
            false,
            [],
        )->returns([[
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]]);

        $pivotSql = 'insert into "pivot_table" ("related_id", "source_id") values (?, ?)';
        $pivotBindings = [456, 123];
        $attachException = new UniqueConstraintViolationException('sqlite', $pivotSql, $pivotBindings, new Exception);

        $source->getConnection()->expects('insert')->with($pivotSql, $pivotBindings)->throws($attachException);
        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            [123, 456],
            false,
        )->times(2)->returns([['exists' => 0]]);

        try {
            $source->related()->createOrFirst(['attr' => 'foo'], ['val' => 'bar']);
            $this->fail('Expected the pivot attach violation to be rethrown.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($attachException, $exception);
        }
    }

    public function testFirstOrCreateMethodRetrievesExistingRelatedAndAssociatesIt(): void
    {
        $this->seedRelated(['attr' => 'foo', 'val' => 'bar']);

        $result = $this->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('bar', $result->val);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame(1, $this->relatedRows());
        $this->assertSame([[123, 1]], $this->pivots());
    }

    public function testFirstOrCreateMethodReturnsExistingRelatedWhenExactPivotExistsAfterAttachCollision(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels([$source, new RelatedModel], 'SQLite');
        $source->getConnection()->allows('transactionLevel')->returns(0);
        $source->getConnection()->allows('getWritableName')->returns('sqlite');

        $source->getConnection()->expects('select')->with(
            'select "related_table".*, "pivot_table"."source_id" as "pivot_source_id", "pivot_table"."related_id" as "pivot_related_id" from "related_table" inner join "pivot_table" on "related_table"."id" = "pivot_table"."related_id" where "pivot_table"."source_id" = ? and ("attr" = ?) limit 1',
            [123, 'foo'],
            true,
            [],
        )->returns([]);

        $source->getConnection()->expects('select')->with(
            'select * from "related_table" where ("attr" = ?) limit 1',
            ['foo'],
            true,
            [],
        )->returns([[
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]]);

        $sql = 'insert into "pivot_table" ("related_id", "source_id") values (?, ?)';
        $bindings = [456, 123];

        $source->getConnection()->expects('insert')->with($sql, $bindings)->throws(
            new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception)
        );

        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            [123, 456],
            false,
        )->returns([['exists' => 1]]);

        $result = $source->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);

        $this->assertSame(456, $result->id);
        $this->assertFalse($result->wasRecentlyCreated);
    }

    public function testFirstOrCreateMethodRethrowsAttachViolationWhenExactPivotIsMissing(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels([$source, new RelatedModel], 'SQLite');
        $source->getConnection()->allows('transactionLevel')->returns(0);
        $source->getConnection()->allows('getWritableName')->returns('sqlite');

        $source->getConnection()->expects('select')->with(
            'select "related_table".*, "pivot_table"."source_id" as "pivot_source_id", "pivot_table"."related_id" as "pivot_related_id" from "related_table" inner join "pivot_table" on "related_table"."id" = "pivot_table"."related_id" where "pivot_table"."source_id" = ? and ("attr" = ?) limit 1',
            [123, 'foo'],
            true,
            [],
        )->returns([]);

        $source->getConnection()->expects('select')->with(
            'select * from "related_table" where ("attr" = ?) limit 1',
            ['foo'],
            true,
            [],
        )->returns([[
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]]);

        $sql = 'insert into "pivot_table" ("related_id", "source_id") values (?, ?)';
        $bindings = [456, 123];
        $attachException = new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception);

        $source->getConnection()->expects('insert')->with($sql, $bindings)->throws($attachException);
        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            [123, 456],
            false,
        )->returns([['exists' => 0]]);

        try {
            $source->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);
            $this->fail('Expected the pivot attach violation to be rethrown.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($attachException, $exception);
        }
    }

    public function testFirstOrCreateMethodAssociatesRelatedOwnedByAnotherSource(): void
    {
        $this->seedRelated(['attr' => 'foo', 'val' => 'bar']);
        $this->seedPivot(999, 1);

        $result = $this->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame(1, $this->relatedRows());
        $this->assertSame([[999, 1], [123, 1]], $this->pivots());
    }

    public function testFirstOrCreateMethodFallsBackToCreateOrFirst(): void
    {
        $result = $this->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame(1, $this->relatedRows());
        $this->assertSame([[123, 1]], $this->pivots());
    }

    public function testUpdateOrCreateMethodCreatesNewRelated(): void
    {
        $result = $this->related()->updateOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertSame('baz', $result->val);
        $this->assertSame('baz', DB::table('related_table')->value('val'));
        $this->assertSame([[123, 1]], $this->pivots());
    }

    public function testUpdateOrCreateMethodUpdatesExistingRelated(): void
    {
        $this->seedRelated(['attr' => 'foo', 'val' => 'bar']);
        $this->seedPivot(123, 1);

        $result = $this->related()->updateOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame('baz', $result->val);
        $this->assertSame('baz', DB::table('related_table')->value('val'));
        $this->assertSame(1, $this->relatedRows());
        $this->assertSame([[123, 1]], $this->pivots());
    }

    public function testUpdateOrCreateMethodUpdatesRelatedOwnedByAnotherSourceAndAssociatesIt(): void
    {
        $this->seedRelated(['attr' => 'foo', 'val' => 'bar']);
        $this->seedPivot(999, 1);

        $result = $this->related()->updateOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame('baz', DB::table('related_table')->value('val'));
        $this->assertSame([[999, 1], [123, 1]], $this->pivots());
    }

    public function testUpdateOrCreateMethodAcceptsClosureValuesAndCreates(): void
    {
        $callCount = 0;

        $result = $this->related()->updateOrCreate(['attr' => 'foo'], function () use (&$callCount) {
            ++$callCount;

            return ['val' => 'baz'];
        });

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertSame(1, $callCount);
        $this->assertSame('baz', $result->val);
        $this->assertSame('baz', DB::table('related_table')->value('val'));
    }

    public function testUpdateOrCreateMethodAcceptsClosureValuesAndUpdates(): void
    {
        $this->seedRelated(['attr' => 'foo', 'val' => 'bar']);
        $this->seedPivot(123, 1);

        $callCount = 0;

        $result = $this->related()->updateOrCreate(['attr' => 'foo'], function () use (&$callCount) {
            ++$callCount;

            return ['val' => 'baz'];
        });

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $callCount);
        $this->assertSame('baz', $result->val);
        $this->assertSame('baz', DB::table('related_table')->value('val'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPivotMembershipCheckIncludesEveryConfiguredPivotConstraint(bool $customRelatedKey): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $related = new RelatedModel;
        $related->id = 456;
        $related->code = 'related-code';
        $this->mockConnectionForModels([$source, $related], 'SQLite');

        $relation = (new InspectableBelongsToMany(
            $related->newQuery(),
            $source,
            'pivot_table',
            'source_id',
            'related_id',
            'id',
            $customRelatedKey ? 'code' : 'id',
        ))
            ->wherePivot('status', 'active')
            ->wherePivotIn('kind', ['primary', 'secondary'])
            ->wherePivotNull('expired_at')
            ->wherePivotBetween('score', [10, 20]);

        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where ("status" = ? and "kind" in (?, ?) and "expired_at" is null and "score" between ? and ?) and "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            ['active', 'primary', 'secondary', 10, 20, 123, $customRelatedKey ? 'related-code' : 456],
            false,
        )->returns([['exists' => 1]]);

        $this->assertTrue($relation->hasAttached($related));
    }

    public function testPivotMembershipCheckIncludesMorphDiscriminator(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $related = new RelatedModel;
        $related->id = 456;
        $this->mockConnectionForModels([$source, $related], 'SQLite');

        $relation = new InspectableMorphToMany(
            $related->newQuery(),
            $source,
            'source',
            'pivot_table',
            'source_id',
            'related_id',
            'id',
            'id',
        );

        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "source_type" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            [123, SourceModel::class, 456],
            false,
        )->returns([['exists' => 1]]);

        $this->assertTrue($relation->hasAttached($related));
    }

    /**
     * Create the relationship for the source model.
     */
    protected function related(): BelongsToMany
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;

        return $source->related();
    }

    /**
     * Insert a related record with the frozen timestamps.
     */
    protected function seedRelated(array $attributes): void
    {
        DB::table('related_table')->insert($attributes + [
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]);
    }

    /**
     * Attach a related record to the given source.
     */
    protected function seedPivot(int $source, int $related): void
    {
        DB::table('pivot_table')->insert(['source_id' => $source, 'related_id' => $related]);
    }

    /**
     * Get the stored source and related key pairs.
     *
     * @return list<array{int, int}>
     */
    protected function pivots(): array
    {
        return DB::table('pivot_table')->orderBy('rowid')->get()
            ->map(fn ($row) => [(int) $row->source_id, (int) $row->related_id])
            ->all();
    }

    /**
     * Count the stored related records.
     */
    protected function relatedRows(): int
    {
        return DB::table('related_table')->count();
    }

    /**
     * Bind controlled connections for pivot collision and query regressions.
     */
    protected function mockConnectionForModels(array $models, string $database): void
    {
        $grammarClass = 'Hypervel\Database\Query\Grammars\\' . $database . 'Grammar';
        $processorClass = 'Hypervel\Database\Query\Processors\\' . $database . 'Processor';
        $processor = new $processorClass;
        $connection = Double::for(Connection::class);
        $connection->allows('getPostProcessor')->returns($processor);
        $grammar = new $grammarClass($connection);
        $connection->allows('getQueryGrammar')->returns($grammar);
        $connection->allows('getTablePrefix')->returns('');
        $connection->allows('query')->resolves(function () use ($connection, $grammar, $processor) {
            return new BaseBuilder($connection, $grammar, $processor);
        });
        $connection->allows('table')->resolves(
            fn ($table, $as = null) => $connection->query()->from($table, $as)
        );
        $connection->allows('getDatabaseName')->returns('database');
        $resolver = Double::for(ConnectionResolverInterface::class);
        $resolver->allows('connection')->returns($connection);

        foreach ($models as $model) {
            /** @var Model $model */
            $class = get_class($model);
            $class::setConnectionResolver($resolver);
        }
    }
}

/**
 * @property int $id
 */
class RelatedModel extends Model
{
    protected ?string $table = 'related_table';

    protected array $guarded = [];
}

/**
 * @property int $id
 */
class SourceModel extends Model
{
    protected ?string $table = 'source_table';

    protected array $guarded = [];

    /**
     * Define the related models.
     */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(
            RelatedModel::class,
            'pivot_table',
            'source_id',
            'related_id',
        );
    }
}

class InspectableBelongsToMany extends BelongsToMany
{
    /**
     * Determine if the related model is attached through the current pivot constraints.
     */
    public function hasAttached(Model $instance): bool
    {
        return $this->hasAttachedPivot($instance);
    }
}

class InspectableMorphToMany extends MorphToMany
{
    /**
     * Determine if the related model is attached through the current morph constraints.
     */
    public function hasAttached(Model $instance): bool
    {
        return $this->hasAttachedPivot($instance);
    }
}
