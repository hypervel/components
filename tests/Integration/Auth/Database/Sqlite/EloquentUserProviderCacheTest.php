<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Auth\Database\EloquentUserProviderCacheTestCase;

#[RequiresDatabase('sqlite')]
class EloquentUserProviderCacheTest extends EloquentUserProviderCacheTestCase
{
}
