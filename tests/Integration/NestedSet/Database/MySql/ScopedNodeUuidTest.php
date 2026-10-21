<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\ScopedNodeUuidTest as BaseScopedNodeUuidTest;

#[RequiresDatabase('mysql', '>=8.0')]
class ScopedNodeUuidTest extends BaseScopedNodeUuidTest
{
}
