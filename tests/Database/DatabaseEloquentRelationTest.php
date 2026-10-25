<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentRelationTest;

use Exception;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use PDO;

class DatabaseEloquentRelationTest extends TestCase
{
    public function testSetRelationFail(): void
    {
        $parent = new ResetModelStub;
        $relation = new ResetModelStub;
        $parent->setRelation('test', $relation);
        $parent->setRelation('foo', 'bar');
        $this->assertArrayNotHasKey('foo', $parent->toArray());
    }

    public function testUnsetExistingRelation(): void
    {
        $parent = new ResetModelStub;
        $relation = new ResetModelStub;
        $parent->setRelation('foo', $relation);
        $parent->unsetRelation('foo');
        $this->assertFalse($parent->relationLoaded('foo'));
    }

    public function testTouchMethodUpdatesRelatedTimestamps(): void
    {
        $connection = $this->newConnection();
        $relation = $this->newHasOne($connection, new NoTouchingModelStub, new NoTouchingModelStub);

        $relation->touch();

        $this->assertSame('2023-01-01 00:00:00', $this->updatedAt($connection, 'table'));
    }

    public function testCanDisableParentTouchingForAllModels(): void
    {
        $connection = $this->newConnection();
        $related = new NoTouchingModelStub;

        $this->assertFalse($related::isIgnoringTouch());

        Model::withoutTouching(function () use ($connection, $related): void {
            $this->assertTrue($related::isIgnoringTouch());

            $this->newHasOne($connection, $related, new NoTouchingModelStub)->touch();
        });

        $this->assertNull($this->updatedAt($connection, 'table'));
        $this->assertFalse($related::isIgnoringTouch());
    }

    public function testCanDisableTouchingForSpecificModel(): void
    {
        $connection = $this->newConnection();
        $related = new NoTouchingModelStub;
        $anotherRelated = new NoTouchingAnotherModelStub;

        $this->assertFalse($related::isIgnoringTouch());
        $this->assertFalse($anotherRelated::isIgnoringTouch());

        NoTouchingModelStub::withoutTouching(function () use ($connection, $related, $anotherRelated): void {
            $this->assertTrue($related::isIgnoringTouch());
            $this->assertFalse($anotherRelated::isIgnoringTouch());

            $this->newHasOne($connection, $related, new NoTouchingModelStub)->touch();
            $this->newHasOne($connection, $anotherRelated, new NoTouchingAnotherModelStub)->touch();
        });

        $this->assertNull($this->updatedAt($connection, 'table'));
        $this->assertSame('2023-01-01 00:00:00', $this->updatedAt($connection, 'another_table'));
        $this->assertFalse($related::isIgnoringTouch());
        $this->assertFalse($anotherRelated::isIgnoringTouch());
    }

    public function testParentModelIsNotTouchedWhenChildModelIsIgnored(): void
    {
        $connection = $this->newConnection();
        $related = new NoTouchingModelStub;
        $relatedChild = new NoTouchingChildModelStub;

        $this->assertFalse($related::isIgnoringTouch());
        $this->assertFalse($relatedChild::isIgnoringTouch());

        NoTouchingModelStub::withoutTouching(function () use ($connection, $related, $relatedChild): void {
            $this->assertTrue($related::isIgnoringTouch());
            $this->assertTrue($relatedChild::isIgnoringTouch());

            $this->newHasOne($connection, $related, new NoTouchingModelStub)->touch();
            $this->newHasOne($connection, $relatedChild, new NoTouchingChildModelStub)->touch();
        });

        $this->assertNull($this->updatedAt($connection, 'table'));
        $this->assertFalse($related::isIgnoringTouch());
        $this->assertFalse($relatedChild::isIgnoringTouch());
    }

    public function testIgnoredModelsStateIsResetWhenThereAreExceptions(): void
    {
        $related = new NoTouchingModelStub;

        $relatedChild = new NoTouchingChildModelStub;

        $this->assertFalse($related::isIgnoringTouch());
        $this->assertFalse($relatedChild::isIgnoringTouch());

        $expectedException = new Exception;
        $relatedIgnoredTouch = null;
        $relatedChildIgnoredTouch = null;
        $caughtException = null;

        try {
            NoTouchingModelStub::withoutTouching(function () use (
                $expectedException,
                $related,
                &$relatedIgnoredTouch,
                $relatedChild,
                &$relatedChildIgnoredTouch,
            ): never {
                $relatedIgnoredTouch = $related::isIgnoringTouch();
                $relatedChildIgnoredTouch = $relatedChild::isIgnoringTouch();

                throw $expectedException;
            });
        } catch (Exception $exception) {
            $caughtException = $exception;
        }

        $this->assertSame($expectedException, $caughtException);
        $this->assertTrue($relatedIgnoredTouch);
        $this->assertTrue($relatedChildIgnoredTouch);
        $this->assertFalse($related::isIgnoringTouch());
        $this->assertFalse($relatedChild::isIgnoringTouch());
    }

    public function testSettingMorphMapWithNumericArrayUsesTheTableNames(): void
    {
        Relation::morphMap([ResetModelStub::class]);

        $this->assertEquals([
            'reset' => ResetModelStub::class,
        ], Relation::morphMap());
    }

    public function testSettingMorphMapWithNumericKeys(): void
    {
        Relation::morphMap([1 => 'App\User']);

        $this->assertEquals([
            1 => 'App\User',
        ], Relation::morphMap());
    }

    public function testGetMorphedModel(): void
    {
        Relation::morphMap(['user' => 'App\User', 1 => 'App\Team']);

        $this->assertSame('App\User', Relation::getMorphedModel('user'));
        $this->assertSame('App\Team', Relation::getMorphedModel(1));
        $this->assertNull(Relation::getMorphedModel('does_not_exist'));
        $this->assertNull(Relation::getMorphedModel(null));
    }

    public function testGetMorphAlias(): void
    {
        Relation::morphMap(['user' => 'App\User', 0 => 'App\Team']);

        $this->assertSame('user', Relation::getMorphAlias('App\User'));
        $this->assertSame(0, Relation::getMorphAlias('App\Team'));
        $this->assertSame('Does\Not\Exist', Relation::getMorphAlias('Does\Not\Exist'));
    }

    public function testWithoutRelations(): void
    {
        $original = new NoTouchingModelStub;

        $original->setRelation('foo', 'baz');

        $this->assertSame('baz', $original->getRelation('foo'));

        $model = $original->withoutRelations();

        $this->assertInstanceOf(NoTouchingModelStub::class, $model);
        $this->assertTrue($original->relationLoaded('foo'));
        $this->assertFalse($model->relationLoaded('foo'));

        $model = $original->unsetRelations();

        $this->assertInstanceOf(NoTouchingModelStub::class, $model);
        $this->assertFalse($original->relationLoaded('foo'));
        $this->assertFalse($model->relationLoaded('foo'));
    }

    public function testWithoutRelation(): void
    {
        $original = new NoTouchingModelStub;

        $original->setRelation('foo', 'baz');
        $original->setRelation('bar', 'qux');

        $model = $original->withoutRelation('foo');

        $this->assertInstanceOf(NoTouchingModelStub::class, $model);
        $this->assertNotSame($model, $original);
        $this->assertTrue($original->relationLoaded('foo'));
        $this->assertTrue($original->relationLoaded('bar'));
        $this->assertFalse($model->relationLoaded('foo'));
        $this->assertTrue($model->relationLoaded('bar'));
    }

    public function testWithoutRelationWithArray(): void
    {
        $original = new NoTouchingModelStub;

        $original->setRelation('foo', 'baz');
        $original->setRelation('bar', 'qux');
        $original->setRelation('bam', 'zap');

        $model = $original->withoutRelation(['foo', 'bar']);

        $this->assertTrue($original->relationLoaded('foo'));
        $this->assertTrue($original->relationLoaded('bar'));
        $this->assertTrue($original->relationLoaded('bam'));
        $this->assertFalse($model->relationLoaded('foo'));
        $this->assertFalse($model->relationLoaded('bar'));
        $this->assertTrue($model->relationLoaded('bam'));
    }

    public function testMacroable(): void
    {
        Relation::macro('foo', function () {
            return 'foo';
        });

        $model = new ResetModelStub;
        $builder = (new Builder($this->newConnection()->query()))->setModel($model);
        $relation = new RelationStub($builder, $model);

        $result = $relation->foo();
        $this->assertSame('foo', $result);
    }

    public function testIsRelationIgnoresAttribute(): void
    {
        $model = new RelationAndAttributeModelStub;

        $this->assertTrue($model->isRelation('parent'));
        $this->assertFalse($model->isRelation('field'));
    }

    /**
     * Create an in-memory connection with one row in each touch target table.
     */
    protected function newConnection(): SQLiteConnection
    {
        CarbonImmutable::setTestNow('2023-01-01 00:00:00');

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('create table "table" ("id" integer primary key, "foreign_key" integer, "updated_at" text)');
        $pdo->exec('create table "another_table" ("id" integer primary key, "foreign_key" integer, "updated_at" text)');
        $pdo->exec('insert into "table" ("id", "foreign_key") values (1, 1)');
        $pdo->exec('insert into "another_table" ("id", "foreign_key") values (1, 2)');

        return new SQLiteConnection($pdo);
    }

    /**
     * Create a has one relationship querying the given connection.
     */
    protected function newHasOne(SQLiteConnection $connection, Model $related, Model $parent): HasOne
    {
        $builder = (new Builder($connection->query()))->setModel($related);

        return new HasOne($builder, $parent, 'foreign_key', 'id');
    }

    /**
     * Get the stored updated_at value of the given table.
     */
    protected function updatedAt(SQLiteConnection $connection, string $table): ?string
    {
        return $connection->scalar('select "updated_at" from "' . $table . '"');
    }
}

class ResetModelStub extends Model
{
    protected ?string $table = 'reset';

    // Override method call which would normally go through __call()

    public function getQuery()
    {
        return $this->newQuery()->getQuery();
    }
}

class RelationStub extends Relation
{
    public function addConstraints(): void
    {
    }

    public function addEagerConstraints(array $models): void
    {
    }

    public function initRelation(array $models, string $relation): array
    {
        return [];
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        return [];
    }

    public function getResults(): mixed
    {
        return null;
    }
}

class NoTouchingModelStub extends Model
{
    protected ?string $table = 'table';

    protected ?string $dateFormat = 'Y-m-d H:i:s';

    protected array $attributes = [
        'id' => 1,
    ];
}

class NoTouchingChildModelStub extends NoTouchingModelStub
{
}

class NoTouchingAnotherModelStub extends Model
{
    protected ?string $table = 'another_table';

    protected ?string $dateFormat = 'Y-m-d H:i:s';

    protected array $attributes = [
        'id' => 2,
    ];
}

class RelationAndAttributeModelStub extends Model
{
    protected ?string $table = 'one_more_table';

    public function field(): Attribute
    {
        return new Attribute(
            function ($value) {
                return $value;
            },
            function ($value) {
                return $value;
            },
        );
    }

    public function parent()
    {
        return $this->belongsTo(self::class);
    }
}
