<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passport\Database\GrantConcurrencyTestCase;

#[RequiresDatabase('mysql')]
class GrantConcurrencyTest extends GrantConcurrencyTestCase
{
}
