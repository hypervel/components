<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\QueryTimeoutTestCase;
use PHPUnit\Framework\Attributes\Large;

#[Large]
#[RequiresDatabase('mariadb')]
class QueryTimeoutTest extends QueryTimeoutTestCase
{
    /**
     * Get the MariaDB timeout error pattern.
     */
    protected function timeoutErrorPattern(): string
    {
        return '/(?:1969|max_statement_time exceeded)/i';
    }
}
