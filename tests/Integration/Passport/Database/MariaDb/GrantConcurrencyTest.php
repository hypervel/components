<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passport\Database\GrantConcurrencyTestCase;

#[RequiresDatabase('mariadb')]
class GrantConcurrencyTest extends GrantConcurrencyTestCase
{
}
