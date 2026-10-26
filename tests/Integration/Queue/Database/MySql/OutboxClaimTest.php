<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Queue\Fixtures\OutboxClaimTestCase;

#[RequiresDatabase('mysql')]
class OutboxClaimTest extends OutboxClaimTestCase
{
}
