<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passkeys\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passkeys\Database\PasskeyCredentialIdTestCase;

#[RequiresDatabase('pgsql')]
class PasskeyCredentialIdTest extends PasskeyCredentialIdTestCase
{
}
