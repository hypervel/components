<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Auth\Database\EloquentUserProviderCacheTestCase;

#[RequiresDatabase('pgsql')]
class EloquentUserProviderCacheTest extends EloquentUserProviderCacheTestCase
{
}
