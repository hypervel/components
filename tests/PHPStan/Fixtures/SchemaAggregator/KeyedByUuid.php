<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Eloquent\Concerns\HasUuids;

trait KeyedByUuid
{
    use HasUuids;
}
