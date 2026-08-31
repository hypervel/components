<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Sanctum\Database\PersonalAccessTokenExpiryTestCase;

#[RequiresDatabase('mysql')]
class PersonalAccessTokenExpiryTest extends PersonalAccessTokenExpiryTestCase
{
}
