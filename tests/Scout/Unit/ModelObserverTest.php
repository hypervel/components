<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\ModelObserver;
use Hypervel\Scout\Searchable;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use ReflectionClass;

class ModelObserverTest extends TestCase
{
    use VerifiesDoubles;

    public function testForceDeletingSoftDeleteModelIsRemovedOnce(): void
    {
        $model = Double::for(ModelObserverSoftDeleteModel::class)->strict();
        $model->expects('wasSearchableBeforeDelete')->returns(true);
        $model->expects('isForceDeleting')->returns(true);
        $model->expects('unsearchable');

        $observer = (new ReflectionClass(ModelObserver::class))->newInstanceWithoutConstructor();

        $observer->deleted($model);
        $observer->forceDeleted($model);
    }
}

class ModelObserverSoftDeleteModel extends Model
{
    use Searchable;
    use SoftDeletes;
}
