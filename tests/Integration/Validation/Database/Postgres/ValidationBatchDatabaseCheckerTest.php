<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Validation\Database\ValidationBatchDatabaseCheckerTestCase;

#[RequiresDatabase('pgsql')]
class ValidationBatchDatabaseCheckerTest extends ValidationBatchDatabaseCheckerTestCase
{
}
