<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passport\Database\TokenPersistenceTestCase;

#[RequiresDatabase('pgsql')]
class TokenPersistenceTest extends TokenPersistenceTestCase
{
}
