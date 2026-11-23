<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Eloquent\Model;

abstract class Person extends Model
{
    use KeyedByUuid;

    protected ?string $table = TypedConstants::USERS;
}
