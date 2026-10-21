<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Hypervel\Data\DataCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Data\Fixtures\MultiData;

class DummyModelWithJson extends Model
{
    protected array $casts = [
        'data' => MultiData::class,
        'data_collection' => DataCollection::class . ':' . MultiData::class,
    ];

    public bool $timestamps = false;
}
