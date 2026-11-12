<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

trait DefinesConnection
{
    protected ?string $connection = 'pgsql';
}
