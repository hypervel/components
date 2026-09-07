<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Auth\Database\EloquentUserProviderCacheTestCase;

#[RequiresDatabase('mysql')]
class EloquentUserProviderCacheTest extends EloquentUserProviderCacheTestCase
{
}
