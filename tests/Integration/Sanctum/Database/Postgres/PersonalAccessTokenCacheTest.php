<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Sanctum\Database\PersonalAccessTokenCacheTestCase;

#[RequiresDatabase('pgsql')]
class PersonalAccessTokenCacheTest extends PersonalAccessTokenCacheTestCase
{
}
