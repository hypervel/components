<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\ScopedNodeUuidTest as BaseScopedNodeUuidTest;

#[RequiresDatabase(['mariadb', 'pgsql'])]
class ScopedNodeUuidTest extends BaseScopedNodeUuidTest
{
}
