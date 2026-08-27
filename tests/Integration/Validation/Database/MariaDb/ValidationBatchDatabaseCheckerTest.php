<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Validation\Database\ValidationBatchDatabaseCheckerTestCase;

#[RequiresDatabase('mariadb')]
class ValidationBatchDatabaseCheckerTest extends ValidationBatchDatabaseCheckerTestCase
{
}
