<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\ClassMorphViolationException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Tests\TestCase;

class DatabaseEloquentStrictMorphsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Relation::requireMorphMap();
    }

    public function testStrictModeThrowsAnExceptionOnClassMap()
    {
        $this->expectException(ClassMorphViolationException::class);

        $model = new StrictMorphsTestModel;

        $model->getMorphClass();
    }

    public function testStrictModeDoesNotThrowExceptionWhenMorphMap()
    {
        $model = new StrictMorphsTestModel;

        Relation::morphMap([
            'test' => StrictMorphsTestModel::class,
        ]);

        $morphName = $model->getMorphClass();
        $this->assertSame('test', $morphName);
    }

    public function testMapsCanBeEnforcedInOneMethod()
    {
        $model = new StrictMorphsTestModel;

        Relation::requireMorphMap(false);

        Relation::enforceMorphMap([
            'test' => StrictMorphsTestModel::class,
        ]);

        $morphName = $model->getMorphClass();
        $this->assertSame('test', $morphName);
    }

    public function testMapIgnoreGenericPivotClass()
    {
        $pivotModel = new Pivot();

        $pivotModel->getMorphClass();
    }

    public function testMapCanBeEnforcedToCustomPivotClass()
    {
        $this->expectException(ClassMorphViolationException::class);

        $pivotModel = new TestPivotModel();

        $pivotModel->getMorphClass();
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);

        parent::tearDown();
    }
}

class StrictMorphsTestModel extends Model
{
}

class TestPivotModel extends Pivot
{
}
