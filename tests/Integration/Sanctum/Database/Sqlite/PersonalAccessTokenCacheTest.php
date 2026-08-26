<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Sanctum\Database\PersonalAccessTokenCacheTestCase;

#[RequiresDatabase('sqlite')]
class PersonalAccessTokenCacheTest extends PersonalAccessTokenCacheTestCase
{
}
