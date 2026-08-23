<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Validation\Database\ValidationBatchDatabaseCheckerTestCase;

#[RequiresDatabase('mysql')]
class ValidationBatchDatabaseCheckerTest extends ValidationBatchDatabaseCheckerTestCase
{
}
