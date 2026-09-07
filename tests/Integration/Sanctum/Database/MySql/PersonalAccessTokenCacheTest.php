<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Sanctum\Database\PersonalAccessTokenCacheTestCase;

#[RequiresDatabase('mysql')]
class PersonalAccessTokenCacheTest extends PersonalAccessTokenCacheTestCase
{
}
