<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Database\Model\Model;

class ModelWithoutRelationStub extends Model
{
    public array $with = ['foo'];

    protected array $guarded = [];

    public function getEagerLoads()
    {
        return $this->eagerLoads;
    }
}
