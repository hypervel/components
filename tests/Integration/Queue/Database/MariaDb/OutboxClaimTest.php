<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Queue\Fixtures\OutboxClaimTestCase;

#[RequiresDatabase('mariadb')]
class OutboxClaimTest extends OutboxClaimTestCase
{
}
