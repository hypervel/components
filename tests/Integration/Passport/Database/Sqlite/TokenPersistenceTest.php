<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passport\Database\TokenPersistenceTestCase;

#[RequiresDatabase('sqlite')]
class TokenPersistenceTest extends TokenPersistenceTestCase
{
}
