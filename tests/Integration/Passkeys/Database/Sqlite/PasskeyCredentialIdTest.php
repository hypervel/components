<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passkeys\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passkeys\Database\PasskeyCredentialIdTestCase;

#[RequiresDatabase('sqlite')]
class PasskeyCredentialIdTest extends PasskeyCredentialIdTestCase
{
}
