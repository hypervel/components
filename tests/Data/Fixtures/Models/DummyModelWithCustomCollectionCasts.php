<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataCollection;

class DummyModelWithCustomCollectionCasts extends DummyModelWithCasts
{
    protected ?string $table = 'dummy_model_with_casts';

    protected array $casts = [
        'data' => SimpleData::class,
        'data_collection' => SimpleDataCollection::class . ':' . SimpleData::class,
    ];
}
