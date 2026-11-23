<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NodeUuidTest as BaseNodeUuidTest;

#[RequiresDatabase(['mariadb', 'pgsql'])]
class NodeUuidTest extends BaseNodeUuidTest
{
}
