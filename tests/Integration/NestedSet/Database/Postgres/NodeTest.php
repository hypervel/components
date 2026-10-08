<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NodeTest as BaseNodeTest;

#[RequiresDatabase('pgsql')]
class NodeTest extends BaseNodeTest
{
}
