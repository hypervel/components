<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\QueryTimeoutTestCase;
use PHPUnit\Framework\Attributes\Large;

#[Large]
#[RequiresDatabase('mysql')]
class QueryTimeoutTest extends QueryTimeoutTestCase
{
    /**
     * Get the MySQL timeout error pattern.
     */
    protected function timeoutErrorPattern(): string
    {
        return '/(?:3024|maximum statement execution time exceeded)/i';
    }
}
