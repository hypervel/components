<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Sanctum\Database\PersonalAccessTokenExpiryTestCase;

#[RequiresDatabase('pgsql')]
class PersonalAccessTokenExpiryTest extends PersonalAccessTokenExpiryTestCase
{
}
