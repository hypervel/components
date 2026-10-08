<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\ScopedNodeTest as BaseScopedNodeTest;

#[RequiresDatabase('pgsql')]
class ScopedNodeTest extends BaseScopedNodeTest
{
}
