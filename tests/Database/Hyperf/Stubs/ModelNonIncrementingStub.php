<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hypervel\Database\Eloquent\Model;

class ModelNonIncrementingStub extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'stub';

    protected array $guarded = [];
}
