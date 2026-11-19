<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Eloquent\Model;

class Country extends Model
{
    protected ?string $primaryKey = 'code';

    protected string $keyType = 'string';

    public bool $incrementing = false;
}
