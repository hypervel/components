<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hypervel\Database\Eloquent\Model;

class ModelWithoutRelationStub extends Model
{
    public array $with = ['foo'];

    protected array $guarded = [];

    public function getEagerLoads()
    {
        return $this->eagerLoads;
    }
}
