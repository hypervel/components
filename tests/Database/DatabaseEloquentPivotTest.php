<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentPivotTest;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Prunable;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;

class DatabaseEloquentPivotTest extends TestCase
{
    use VerifiesDoubles;

    public function testPropertiesAreSetCorrectly(): void
    {
        $parent = Double::for(Model::class)->passthru();
        $parent->expects('getConnectionName')->times(1)->returns('connection');
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $parent->setConnectionResolver($resolver);
        $connection = Double::for(Connection::class)->strict();
        $resolver->expects('connection')->times(1)->returns($connection);
        $grammar = new Grammar($connection);
        $connection->expects('getQueryGrammar')->times(1)->returns($grammar);
        $parent->setDateFormat('Y-m-d H:i:s');
        $pivot = Pivot::fromAttributes($parent, ['foo' => 'bar', 'created_at' => '2015-09-12'], 'table', true);

        $this->assertEquals(['foo' => 'bar', 'created_at' => '2015-09-12 00:00:00'], $pivot->getAttributes());
        $this->assertSame('connection', $pivot->getConnectionName());
        $this->assertSame('table', $pivot->getTable());
        $this->assertTrue($pivot->exists);
        $this->assertSame($parent, $pivot->pivotParent);
    }

    public function testMutatorsAreCalledFromConstructor(): void
    {
        $parent = new DummyModel;

        $pivot = MutatorStub::fromAttributes($parent, ['foo' => 'bar'], 'table', true);

        $this->assertTrue($pivot->getMutatorCalled());
    }

    public function testFromRawAttributesDoesNotDoubleMutate(): void
    {
        $parent = Double::for(Model::class)->passthru();
        $parent->expects('getConnectionName')->returns('connection');

        $pivot = JsonCastStub::fromRawAttributes($parent, ['foo' => json_encode(['name' => 'Taylor'])], 'table', true);

        $this->assertEquals(['name' => 'Taylor'], $pivot->foo);
    }

    public function testFromRawAttributesDoesNotMutate(): void
    {
        $parent = Double::for(Model::class)->passthru();
        $parent->expects('getConnectionName')->returns('connection');

        $pivot = MutatorStub::fromRawAttributes($parent, ['foo' => 'bar'], 'table', true);

        $this->assertFalse($pivot->getMutatorCalled());
    }

    public function testPropertiesUnchangedAreNotDirty(): void
    {
        $parent = Double::for(Model::class)->passthru();
        $parent->expects('getConnectionName')->returns('connection');
        $pivot = Pivot::fromAttributes($parent, ['foo' => 'bar', 'shimy' => 'shake'], 'table', true);

        $this->assertSame([], $pivot->getDirty());
    }

    public function testPropertiesChangedAreDirty(): void
    {
        $parent = Double::for(Model::class)->passthru();
        $parent->expects('getConnectionName')->returns('connection');
        $pivot = Pivot::fromAttributes($parent, ['foo' => 'bar', 'shimy' => 'shake'], 'table', true);
        $pivot->shimy = 'changed';

        $this->assertEquals(['shimy' => 'changed'], $pivot->getDirty());
    }

    public function testTimestampPropertyIsSetIfCreatedAtInAttributes(): void
    {
        $parent = Double::for(Model::class)->passthru();
        $parent->expects('getConnectionName')->times(2)->returns('connection');
        $pivot = DateStub::fromAttributes($parent, ['foo' => 'bar', 'created_at' => 'foo'], 'table');
        $this->assertTrue($pivot->timestamps);

        $pivot = DateStub::fromAttributes($parent, ['foo' => 'bar'], 'table');
        $this->assertFalse($pivot->timestamps);
    }

    public function testTimestampPropertyIsTrueWhenCreatingFromRawAttributes(): void
    {
        $parent = Double::for(Model::class)->passthru();
        $parent->expects('getConnectionName')->returns('connection');
        $pivot = Pivot::fromRawAttributes($parent, ['foo' => 'bar', 'created_at' => 'foo'], 'table');
        $this->assertTrue($pivot->timestamps);
    }

    public function testKeysCanBeSetProperly(): void
    {
        $parent = new DummyModel;
        $pivot = Pivot::fromAttributes($parent, ['foo' => 'bar'], 'table');
        $pivot->setPivotKeys('foreign', 'other');

        $this->assertSame('foreign', $pivot->getForeignKey());
        $this->assertSame('other', $pivot->getOtherKey());
    }

    public function testDeleteMethodDeletesModelByKeys(): void
    {
        $pivot = $this->getMockBuilder(Pivot::class)->onlyMethods(['newQueryWithoutRelationships'])->getMock();
        $pivot->setPivotKeys('foreign', 'other');
        $pivot->foreign = 'foreign.value';
        $pivot->other = 'other.value';
        $query = Double::for(Builder::class)->strict();
        $query->expects('where')->with(['foreign' => 'foreign.value', 'other' => 'other.value'])->returns($query);
        $query->expects('delete')->returns(1);
        $pivot->expects($this->once())->method('newQueryWithoutRelationships')->willReturn($query);

        $rowsAffected = $pivot->delete();
        $this->assertEquals(1, $rowsAffected);
    }

    public function testModelDeleteWrappersReturnAffectedRowCountForPivots(): void
    {
        $pivot = new DeleteReturnPivotStub;
        $pivot->exists = true;

        $pivot->setConnectionResolver($resolver = Double::for(ConnectionResolverInterface::class)->strict());
        $resolver->expects('connection')->returns($connection = Double::for(Connection::class)->strict());
        $connection->expects('transaction')->resolves(fn ($callback) => $callback());

        $this->assertSame(1, $pivot->deleteQuietly());
        $this->assertSame(1, $pivot->deleteOrFail());
        $this->assertSame(1, $pivot->forceDelete());
    }

    public function testPrunableReturnsAffectedRowCountForPivots(): void
    {
        $pivot = new PrunableDeleteReturnPivotStub;
        $pivot->exists = true;

        $this->assertSame(1, $pivot->prune());
    }

    public function testPivotModelTableNameIsSingular(): void
    {
        $pivot = new Pivot;

        $this->assertSame('pivot', $pivot->getTable());
    }

    #[TestWith([Pivot::class, 1])]
    #[TestWith([Pivot::class, [1, 2]])]
    #[TestWith([Pivot::class, 'first'])]
    #[TestWith([Pivot::class, ['first', 'second']])]
    #[TestWith([MorphPivot::class, 1])]
    #[TestWith([MorphPivot::class, [1, 2]])]
    #[TestWith([MorphPivot::class, 'first'])]
    #[TestWith([MorphPivot::class, ['first', 'second']])]
    public function testRestorationQueriesUsePrimaryKeys(string $pivotClass, array|int|string $ids): void
    {
        $pivot = $this->getMockBuilder($pivotClass)->onlyMethods(['newQueryWithoutScopes'])->getMock();
        $query = Double::for(Builder::class)->strict();
        $query->expects('whereKey')->with($ids)->returns($query);
        $pivot->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);

        $this->assertSame($query, $pivot->newQueryForRestoration($ids));
    }

    public function testPivotModelWithParentReturnsParentsTimestampColumns(): void
    {
        $parent = new DatabaseEloquentPivotTestCustomTimestampsModel;

        $pivotWithParent = new Pivot;
        $pivotWithParent->pivotParent = $parent;

        $this->assertSame('parent_created_at', $pivotWithParent->getCreatedAtColumn());
        $this->assertSame('parent_updated_at', $pivotWithParent->getUpdatedAtColumn());
    }

    public function testPivotModelWithoutParentReturnsModelTimestampColumns(): void
    {
        $model = new DummyModel;

        $pivotWithoutParent = new Pivot;

        $this->assertEquals($model->getCreatedAtColumn(), $pivotWithoutParent->getCreatedAtColumn());
        $this->assertEquals($model->getUpdatedAtColumn(), $pivotWithoutParent->getUpdatedAtColumn());
    }

    public function testWithoutRelations(): void
    {
        $original = new Pivot;

        $parentModel = Double::for(Model::class)->strict();
        $original->pivotParent = $parentModel;
        $original->setRelation('bar', 'baz');

        $this->assertSame('baz', $original->getRelation('bar'));

        $pivot = $original->withoutRelations();

        $this->assertInstanceOf(Pivot::class, $pivot);
        $this->assertNotSame($pivot, $original);
        $this->assertSame($parentModel, $original->pivotParent);
        $this->assertNull($pivot->pivotParent);
        $this->assertTrue($original->relationLoaded('bar'));
        $this->assertFalse($pivot->relationLoaded('bar'));

        $pivot = $original->unsetRelations();

        $this->assertSame($pivot, $original);
        $this->assertNull($pivot->pivotParent);
        $this->assertFalse($pivot->relationLoaded('bar'));
    }
}

class DateStub extends Pivot
{
    public function getDates(): array
    {
        return [];
    }
}

class MutatorStub extends Pivot
{
    private $mutatorCalled = false;

    public function setFooAttribute($value)
    {
        $this->mutatorCalled = true;

        return $value;
    }

    public function getMutatorCalled()
    {
        return $this->mutatorCalled;
    }
}

class JsonCastStub extends Pivot
{
    protected array $casts = [
        'foo' => 'json',
    ];
}

class DeleteReturnPivotStub extends Pivot
{
    /**
     * Return a simulated affected-row count.
     */
    public function delete(): int
    {
        return 1;
    }
}

class PrunableDeleteReturnPivotStub extends DeleteReturnPivotStub
{
    use Prunable;
}

class DummyModel extends Model
{
}

class DatabaseEloquentPivotTestCustomTimestampsModel extends Model
{
    public const ?string CREATED_AT = 'parent_created_at';

    public const ?string UPDATED_AT = 'parent_updated_at';
}
