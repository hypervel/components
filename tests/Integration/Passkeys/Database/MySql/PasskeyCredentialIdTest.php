<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passkeys\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passkeys\Database\PasskeyCredentialIdTestCase;

#[RequiresDatabase('mysql')]
class PasskeyCredentialIdTest extends PasskeyCredentialIdTestCase
{
}
