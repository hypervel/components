<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\ModelObserver;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;

class ModelObserverTest extends TestCase
{
    public function testForceDeletingSoftDeleteModelIsRemovedOnce(): void
    {
        $model = m::mock(ModelObserverSoftDeleteModel::class . ', ' . SearchableInterface::class);
        $model->shouldReceive('wasSearchableBeforeDelete')->once()->andReturnTrue();
        $model->shouldReceive('isForceDeleting')->once()->andReturnTrue();
        $model->shouldReceive('unsearchable')->once();

        $observer = (new ReflectionClass(ModelObserver::class))->newInstanceWithoutConstructor();

        $observer->deleted($model);
        $observer->forceDeleted($model);
    }
}

class ModelObserverSoftDeleteModel extends Model
{
    use SoftDeletes;
}
