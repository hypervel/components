<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Sanctum\Database\PersonalAccessTokenExpiryTestCase;

#[RequiresDatabase('mariadb')]
class PersonalAccessTokenExpiryTest extends PersonalAccessTokenExpiryTestCase
{
}
