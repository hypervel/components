<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Ai\Database\ConversationConcurrencyTestCase;

#[RequiresDatabase('pgsql')]
class ConversationConcurrencyTest extends ConversationConcurrencyTestCase
{
}
