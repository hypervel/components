<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passport\Database\TokenPersistenceTestCase;

#[RequiresDatabase('mysql')]
class TokenPersistenceTest extends TokenPersistenceTestCase
{
}
