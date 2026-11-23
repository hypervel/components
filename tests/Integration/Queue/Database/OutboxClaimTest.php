<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database;

use Hypervel\Testbench\Attributes\RequiresDatabase;

#[RequiresDatabase(['mysql', 'mariadb', 'pgsql'])]
class OutboxClaimTest extends OutboxClaimTestCase
{
}
