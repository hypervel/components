<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataCollection;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithDefaultValue;

class DummyModelWithDefaultCasts extends Model
{
    protected array $casts = [
        'data' => SimpleDataWithDefaultValue::class . ':default',
        'data_collection' => SimpleDataCollection::class . ':' . SimpleData::class . ',default',
    ];

    protected ?string $table = 'dummy_model_with_casts';

    public bool $timestamps = false;
}
