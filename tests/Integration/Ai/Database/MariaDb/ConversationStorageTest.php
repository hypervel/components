<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Ai\Database\ConversationStorageTestCase;

#[RequiresDatabase('mariadb')]
class ConversationStorageTest extends ConversationStorageTestCase
{
}
