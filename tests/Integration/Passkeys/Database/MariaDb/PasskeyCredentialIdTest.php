<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passkeys\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passkeys\Database\PasskeyCredentialIdTestCase;

#[RequiresDatabase('mariadb')]
class PasskeyCredentialIdTest extends PasskeyCredentialIdTestCase
{
}
