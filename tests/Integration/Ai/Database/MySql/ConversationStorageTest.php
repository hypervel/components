<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Ai\Database\ConversationStorageTestCase;

#[RequiresDatabase('mysql')]
class ConversationStorageTest extends ConversationStorageTestCase
{
}
