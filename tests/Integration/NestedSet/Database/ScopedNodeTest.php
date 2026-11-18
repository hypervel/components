<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\ScopedNodeTest as BaseScopedNodeTest;

#[RequiresDatabase(['mariadb', 'pgsql'])]
class ScopedNodeTest extends BaseScopedNodeTest
{
}
