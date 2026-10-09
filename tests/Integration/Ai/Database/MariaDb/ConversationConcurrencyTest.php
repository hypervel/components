<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Ai\Database\ConversationConcurrencyTestCase;

#[RequiresDatabase('mariadb')]
class ConversationConcurrencyTest extends ConversationConcurrencyTestCase
{
}
