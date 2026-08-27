<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Validation\Database\ValidationBatchDatabaseCheckerTestCase;

#[RequiresDatabase('sqlite')]
class ValidationBatchDatabaseCheckerTest extends ValidationBatchDatabaseCheckerTestCase
{
}
