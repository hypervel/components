<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passport\Database\TokenPersistenceTestCase;

#[RequiresDatabase('mariadb')]
class TokenPersistenceTest extends TokenPersistenceTestCase
{
}
