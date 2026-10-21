<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\ScopedNodeTest as BaseScopedNodeTest;

#[RequiresDatabase('mysql', '>=8.0')]
class ScopedNodeTest extends BaseScopedNodeTest
{
}
