<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Contracts\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentModelScopeTest extends DatabaseTestCase
{
    public function testModelHasScope()
    {
        $model = new TestScopeModel1();

        $this->assertTrue($model->hasNamedScope('exists'));
    }

    public function testModelDoesNotHaveScope()
    {
        $model = new TestScopeModel1();

        $this->assertFalse($model->hasNamedScope('doesNotExist'));
    }

    public function testModelHasAttributedScope()
    {
        $model = new TestScopeModel1();

        $this->assertTrue($model->hasNamedScope('existsAsWell'));
    }
}

class TestScopeModel1 extends Model
{
    public function scopeExists(Builder $builder): Builder
    {
        return $builder;
    }

    #[Scope]
    protected function existsAsWell(Builder $builder): Builder
    {
        return $builder;
    }
}
