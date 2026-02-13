<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentGlobalScopesTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Attributes\ScopedBy;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Scope;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentGlobalScopesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        tap(new DB())->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ])->bootEloquent();
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();

        parent::tearDown();
    }

    public function testGlobalScopeIsApplied()
    {
        $model = new GlobalScopesModel();
        $query = $model->newQuery();
        $this->assertSame('select * from "table" where "active" = ?', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testGlobalScopeCanBeRemoved()
    {
        $model = new GlobalScopesModel();
        $query = $model->newQuery()->withoutGlobalScope(ActiveScope::class);
        $this->assertSame('select * from "table"', $query->toSql());
        $this->assertEquals([], $query->getBindings());
    }

    public function testClassNameGlobalScopeIsApplied()
    {
        $model = new ClassNameGlobalScopesModel();
        $query = $model->newQuery();
        $this->assertSame('select * from "table" where "active" = ?', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testGlobalScopeInAttributeIsApplied()
    {
        $model = new GlobalScopeInAttributeModel();
        $query = $model->newQuery();
        $this->assertSame('select * from "table" where "active" = ?', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testGlobalScopeInInheritedAttributeIsApplied()
    {
        $model = new GlobalScopeInInheritedAttributeModel();
        $query = $model->newQuery();
        $this->assertSame('select * from "table" where "active" = ?', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testClosureGlobalScopeIsApplied()
    {
        $model = new ClosureGlobalScopesModel();
        $query = $model->newQuery();
        $this->assertSame('select * from "table" where "active" = ? order by "name" asc', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testGlobalScopesCanBeRegisteredViaArray()
    {
        $model = new GlobalScopesArrayModel();
        $query = $model->newQuery();
        $this->assertSame('select * from "table" where "active" = ? order by "name" asc', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testClosureGlobalScopeCanBeRemoved()
    {
        $model = new ClosureGlobalScopesModel();
        $query = $model->newQuery()->withoutGlobalScope('active_scope');
        $this->assertSame('select * from "table" order by "name" asc', $query->toSql());
        $this->assertEquals([], $query->getBindings());
    }

    public function testGlobalScopeCanBeRemovedAfterTheQueryIsExecuted()
    {
        $model = new ClosureGlobalScopesModel();
        $query = $model->newQuery();
        $this->assertSame('select * from "table" where "active" = ? order by "name" asc', $query->toSql());
        $this->assertEquals([1], $query->getBindings());

        $query->withoutGlobalScope('active_scope');
        $this->assertSame('select * from "table" order by "name" asc', $query->toSql());
        $this->assertEquals([], $query->getBindings());
    }

    public function testAllGlobalScopesCanBeRemoved()
    {
        $model = new ClosureGlobalScopesModel();
        $query = $model->newQuery()->withoutGlobalScopes();
        $this->assertSame('select * from "table"', $query->toSql());
        $this->assertEquals([], $query->getBindings());

        $query = ClosureGlobalScopesModel::withoutGlobalScopes();
        $this->assertSame('select * from "table"', $query->toSql());
        $this->assertEquals([], $query->getBindings());
    }

    public function testAllGlobalScopesCanBeRemovedExceptSpecified()
    {
        $model = new ClosureGlobalScopesModel();
        $query = $model->newQuery()->withoutGlobalScopesExcept(['active_scope']);
        $this->assertSame('select * from "table" where "active" = ?', $query->toSql());
        $this->assertEquals([1], $query->getBindings());

        $query = ClosureGlobalScopesModel::withoutGlobalScopesExcept(['active_scope']);
        $this->assertSame('select * from "table" where "active" = ?', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testGlobalScopesWithOrWhereConditionsAreNested()
    {
        $model = new ClosureGlobalScopesWithOrModel();

        $query = $model->newQuery();
        $this->assertSame('select "email", "password" from "table" where ("email" = ? or "email" = ?) and "active" = ? order by "name" asc', $query->toSql());
        $this->assertEquals(['taylor@gmail.com', 'someone@else.com', 1], $query->getBindings());

        $query = $model->newQuery()->where('col1', 'val1')->orWhere('col2', 'val2');
        $this->assertSame('select "email", "password" from "table" where ("col1" = ? or "col2" = ?) and ("email" = ? or "email" = ?) and "active" = ? order by "name" asc', $query->toSql());
        $this->assertEquals(['val1', 'val2', 'taylor@gmail.com', 'someone@else.com', 1], $query->getBindings());
    }

    public function testRegularScopesWithOrWhereConditionsAreNested()
    {
        $query = ClosureGlobalScopesModel::withoutGlobalScopes()->where('foo', 'foo')->orWhere('bar', 'bar')->approved();

        $this->assertSame('select * from "table" where ("foo" = ? or "bar" = ?) and ("approved" = ? or "should_approve" = ?)', $query->toSql());
        $this->assertEquals(['foo', 'bar', 1, 0], $query->getBindings());
    }

    public function testScopesStartingWithOrBooleanArePreserved()
    {
        $query = ClosureGlobalScopesModel::withoutGlobalScopes()->where('foo', 'foo')->orWhere('bar', 'bar')->orApproved();

        $this->assertSame('select * from "table" where ("foo" = ? or "bar" = ?) or ("approved" = ? or "should_approve" = ?)', $query->toSql());
        $this->assertEquals(['foo', 'bar', 1, 0], $query->getBindings());
    }

    public function testHasQueryWhereBothModelsHaveGlobalScopes()
    {
        $query = GlobalScopesWithRelationModel::has('related')->where('bar', 'baz');

        $subQuery = 'select * from "table" where "table2"."id" = "table"."related_id" and "foo" = ? and "active" = ?';
        $mainQuery = 'select * from "table2" where exists (' . $subQuery . ') and "bar" = ? and "active" = ? order by "name" asc';

        $this->assertEquals($mainQuery, $query->toSql());
        $this->assertEquals(['bar', 1, 'baz', 1], $query->getBindings());
    }
}

class ClosureGlobalScopesModel extends Model
{
    protected ?string $table = 'table';

    public static function boot(): void
    {
        static::addGlobalScope(function ($query) {
            $query->orderBy('name');
        });

        static::addGlobalScope('active_scope', function ($query) {
            $query->where('active', 1);
        });

        parent::boot();
    }

    public function scopeApproved($query)
    {
        return $query->where('approved', 1)->orWhere('should_approve', 0);
    }

    public function scopeOrApproved($query)
    {
        return $query->orWhere('approved', 1)->orWhere('should_approve', 0);
    }
}

class GlobalScopesWithRelationModel extends ClosureGlobalScopesModel
{
    protected ?string $table = 'table2';

    public function related()
    {
        return $this->hasMany(GlobalScopesModel::class, 'related_id')->where('foo', 'bar');
    }
}

class ClosureGlobalScopesWithOrModel extends ClosureGlobalScopesModel
{
    public static function boot(): void
    {
        static::addGlobalScope('or_scope', function ($query) {
            $query->where('email', 'taylor@gmail.com')->orWhere('email', 'someone@else.com');
        });

        static::addGlobalScope(function ($query) {
            $query->select('email', 'password');
        });

        parent::boot();
    }
}

class GlobalScopesModel extends Model
{
    protected ?string $table = 'table';

    public static function boot(): void
    {
        static::addGlobalScope(new ActiveScope());

        parent::boot();
    }
}

class ClassNameGlobalScopesModel extends Model
{
    protected ?string $table = 'table';

    public static function boot(): void
    {
        static::addGlobalScope(ActiveScope::class);

        parent::boot();
    }
}

class GlobalScopesArrayModel extends Model
{
    protected ?string $table = 'table';

    public static function boot(): void
    {
        static::addGlobalScopes([
            'active_scope' => new ActiveScope(),
            fn ($query) => $query->orderBy('name'),
        ]);

        parent::boot();
    }
}

#[ScopedBy(ActiveScope::class)]
class GlobalScopeInAttributeModel extends Model
{
    protected ?string $table = 'table';
}

class ActiveScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('active', 1);
    }
}

#[ScopedBy(ActiveScope::class)]
trait GlobalScopeInInheritedAttributeTrait
{
}

class GlobalScopeInInheritedAttributeModel extends Model
{
    use GlobalScopeInInheritedAttributeTrait;

    protected ?string $table = 'table';
}
