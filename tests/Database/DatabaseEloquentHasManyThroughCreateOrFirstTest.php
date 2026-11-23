<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentHasManyThroughCreateOrFirstTest;

use Closure;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Events\Dispatcher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseEloquentHasManyThroughCreateOrFirstTest extends TestCase
{
    /**
     * Set up the relationship database.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2023-01-01 00:00:00');

        $db = new DB;

        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setEventDispatcher(new Dispatcher);
        $db->bootEloquent();
        $db->setAsGlobal();

        $pdo = $db->getConnection()->getPdo();

        $pdo->exec('create table "pivot" ("id" integer primary key autoincrement not null, "parent_id" integer not null)');
        $pdo->exec('create table "child" (
            "id" integer primary key autoincrement not null,
            "pivot_id" integer null,
            "attr" varchar not null unique,
            "val" varchar null,
            "created_at" datetime null,
            "updated_at" datetime null
        )');

        // The parent owns the child through this pivot row...
        DB::table('pivot')->insert(['id' => 456, 'parent_id' => 123]);
        DB::table('pivot')->insert(['id' => 457, 'parent_id' => 999]);
    }

    #[DataProvider('createOrFirstValues')]
    public function testCreateOrFirstMethodCreatesNewRecord(Closure|array $values): void
    {
        $result = $this->children()->createOrFirst(['attr' => 'foo'], $values);

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
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar', 'pivot_id' => 456]);

        $result = $this->children()->createOrFirst(['attr' => 'foo'], ['val' => 'bar']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'pivot_id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
            'hypervel_through_key' => 123,
        ], $result->toArray());
        $this->assertSame(1, $this->rows());
    }

    public function testFirstOrCreateMethodCreatesNewRecord(): void
    {
        $result = $this->children()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);

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

    public function testFirstOrCreateMethodRetrievesExistingRecord(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar', 'pivot_id' => 456]);

        $result = $this->children()->firstOrCreate(['attr' => 'foo'], ['val' => 'baz']);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('bar', $result->val);
        $this->assertSame(1, $this->rows());
        $this->assertEquals([
            'id' => 1,
            'pivot_id' => 456,
            'hypervel_through_key' => 123,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    #[DataProvider('createOrFirstValues')]
    public function testFirstOrCreateMethodRetrievesRecordCreatedJustNow(Closure|array $values): void
    {
        $this->competingInsert(['attr' => 'foo', 'val' => 'other', 'pivot_id' => 456]);

        $result = $this->children()->firstOrCreate(['attr' => 'foo'], $values);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame(123, $result->hypervel_through_key);
        $this->assertSame(1, $this->rows());
        $this->assertEquals([
            'id' => 1,
            'pivot_id' => 456,
            'hypervel_through_key' => 123,
            'attr' => 'foo',
            'val' => 'other',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
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

    public function testFirstOrCreateThrowsWhenAnotherParentsRecordHoldsTheUniqueValue(): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar', 'pivot_id' => 457]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->children()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);
    }

    #[DataProvider('updateOrCreateValues')]
    public function testUpdateOrCreateMethodCreatesNewRecord(Closure|array $values): void
    {
        $result = $this->children()->updateOrCreate(['attr' => 'foo'], $values);

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 1,
            'attr' => 'foo',
            'val' => 'baz',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
        $this->assertSame(1, $this->rows());
    }

    #[DataProvider('updateOrCreateValues')]
    public function testUpdateOrCreateMethodUpdatesExistingRecord(Closure|array $values): void
    {
        $this->seedRecord(['attr' => 'foo', 'val' => 'bar', 'pivot_id' => 456]);

        $result = $this->children()->updateOrCreate(['attr' => 'foo'], $values);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('baz', $result->val);
        $this->assertSame('baz', DB::table('child')->where('id', 1)->value('val'));
        $this->assertSame(1, $this->rows());
        $this->assertEquals([
            'id' => 1,
            'pivot_id' => 456,
            'hypervel_through_key' => 123,
            'attr' => 'foo',
            'val' => 'baz',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    #[DataProvider('updateOrCreateValues')]
    public function testUpdateOrCreateMethodUpdatesRecordCreatedJustNow(Closure|array $values): void
    {
        $this->competingInsert(['attr' => 'foo', 'val' => 'bar', 'pivot_id' => 456]);

        $result = $this->children()->updateOrCreate(['attr' => 'foo'], $values);

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, $result->id);
        $this->assertSame('baz', DB::table('child')->where('id', 1)->value('val'));
        $this->assertSame(1, $this->rows());
        $this->assertEquals([
            'id' => 1,
            'pivot_id' => 456,
            'hypervel_through_key' => 123,
            'attr' => 'foo',
            'val' => 'baz',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    /**
     * Provide array and closure update values.
     */
    public static function updateOrCreateValues(): array
    {
        return [
            'array' => [['val' => 'baz']],
            'closure' => [fn () => ['val' => 'baz']],
        ];
    }

    /**
     * Get the parent's children through the pivot model.
     */
    protected function children(): HasManyThrough
    {
        $parent = new ParentModel;
        $parent->id = 123;
        $parent->exists = true;

        return $parent->children();
    }

    /**
     * Insert a child record.
     */
    protected function seedRecord(array $attributes): void
    {
        DB::table('child')->insert($attributes + [
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]);
    }

    /**
     * Have another process create the record right before this one is inserted.
     */
    protected function competingInsert(array $attributes): void
    {
        ChildModel::creating(function () use ($attributes) {
            ChildModel::flushEventListeners();

            $this->seedRecord($attributes);
        });
    }

    /**
     * Count the child records.
     */
    protected function rows(): int
    {
        return DB::table('child')->count();
    }
}

/**
 * @property int $id
 * @property int $pivot_id
 */
class ChildModel extends Model
{
    protected ?string $table = 'child';

    protected array $guarded = [];
}

/**
 * @property int $id
 * @property int $parent_id
 */
class PivotModel extends Model
{
    protected ?string $table = 'pivot';

    protected array $guarded = [];
}

/**
 * @property int $id
 */
class ParentModel extends Model
{
    protected ?string $table = 'parent';

    protected array $guarded = [];

    /**
     * Get the parent's children through the pivot model.
     */
    public function children(): HasManyThrough
    {
        return $this->hasManyThrough(
            ChildModel::class,
            PivotModel::class,
            'parent_id',
            'pivot_id',
        );
    }
}
