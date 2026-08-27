<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Auth\Database\EloquentUserProviderCacheTestCase;

#[RequiresDatabase('mariadb')]
class EloquentUserProviderCacheTest extends EloquentUserProviderCacheTestCase
{
}
