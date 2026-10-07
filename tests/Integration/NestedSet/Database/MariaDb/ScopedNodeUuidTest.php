<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\ScopedNodeUuidTest as BaseScopedNodeUuidTest;

#[RequiresDatabase('mariadb')]
class ScopedNodeUuidTest extends BaseScopedNodeUuidTest
{
}
