<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passport\Database\PassportSchemaTestCase;

#[RequiresDatabase('pgsql')]
class PassportSchemaTest extends PassportSchemaTestCase
{
}
