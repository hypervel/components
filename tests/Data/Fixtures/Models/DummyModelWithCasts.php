<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Hypervel\Data\DataCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Data\Fixtures\AbstractData\AbstractData;
use Hypervel\Tests\Data\Fixtures\LazyData;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class DummyModelWithCasts extends Model
{
    protected array $guarded = [];

    protected array $casts = [
        'data' => SimpleData::class,
        'lazy_data' => LazyData::class,
        'data_collection' => DataCollection::class . ':' . SimpleData::class,
        'lazy_data_collection' => DataCollection::class . ':' . LazyData::class,
        'abstract_data' => AbstractData::class,
        'abstract_collection' => DataCollection::class . ':' . AbstractData::class,
    ];

    public bool $timestamps = false;
}
