<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Hypervel\Data\DataCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Data\Fixtures\AbstractData\AbstractData;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataCollection;

class DummyModelWithEncryptedCasts extends Model
{
    protected array $guarded = [];

    protected array $casts = [
        'data' => SimpleData::class . ':encrypted',
        'data_collection' => SimpleDataCollection::class . ':' . SimpleData::class . ',encrypted',
        'abstract_data' => AbstractData::class . ':encrypted',
        'abstract_collection' => DataCollection::class . ':' . AbstractData::class . ',encrypted',
    ];

    protected ?string $table = 'dummy_model_with_casts';

    public bool $timestamps = false;
}
