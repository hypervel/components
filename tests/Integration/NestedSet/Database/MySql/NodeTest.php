<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\NodeTest as BaseNodeTest;

#[RequiresDatabase('mysql', '>=8.0')]
class NodeTest extends BaseNodeTest
{
}
