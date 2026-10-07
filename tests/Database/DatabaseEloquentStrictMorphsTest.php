<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentStrictMorphsTest;

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

    public function testStrictModeThrowsAnExceptionOnClassMap(): void
    {
        $this->expectException(ClassMorphViolationException::class);

        $model = new ModelStub;

        $model->getMorphClass();
    }

    public function testStrictModeDoesNotThrowExceptionWhenMorphMap(): void
    {
        $model = new ModelStub;

        Relation::morphMap([
            'test' => ModelStub::class,
        ]);

        $morphName = $model->getMorphClass();
        $this->assertSame('test', $morphName);
    }

    public function testMapsCanBeEnforcedInOneMethod(): void
    {
        $model = new ModelStub;

        Relation::requireMorphMap(false);

        Relation::enforceMorphMap([
            'test' => ModelStub::class,
        ]);

        $morphName = $model->getMorphClass();
        $this->assertSame('test', $morphName);
    }

    public function testMapIgnoreGenericPivotClass(): void
    {
        $pivotModel = new Pivot;

        $pivotModel->getMorphClass();
    }

    public function testMapCanBeEnforcedToCustomPivotClass(): void
    {
        $this->expectException(ClassMorphViolationException::class);

        $pivotModel = new PivotStub;

        $pivotModel->getMorphClass();
    }
}

class ModelStub extends Model
{
}

class PivotStub extends Pivot
{
}
