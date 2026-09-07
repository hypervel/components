<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Sanctum\Database\PersonalAccessTokenExpiryTestCase;

#[RequiresDatabase('sqlite')]
class PersonalAccessTokenExpiryTest extends PersonalAccessTokenExpiryTestCase
{
}
