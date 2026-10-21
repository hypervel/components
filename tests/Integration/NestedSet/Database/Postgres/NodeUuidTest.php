<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NodeUuidTest as BaseNodeUuidTest;

#[RequiresDatabase('pgsql')]
class NodeUuidTest extends BaseNodeUuidTest
{
}
