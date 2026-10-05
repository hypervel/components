<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Data;
use Hypervel\Data\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class DataWithMapper extends Data
{
    public string $casedProperty;

    public SimpleData $dataCasedProperty;

    #[DataCollectionOf(SimpleData::class)]
    public array $dataCollectionCasedProperty;
}
