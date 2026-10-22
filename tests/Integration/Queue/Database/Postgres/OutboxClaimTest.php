<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Queue\Fixtures\OutboxClaimTestCase;

#[RequiresDatabase('pgsql')]
class OutboxClaimTest extends OutboxClaimTestCase
{
}
