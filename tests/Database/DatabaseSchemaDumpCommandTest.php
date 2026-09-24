<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Console\DumpCommand;
use Hypervel\Testbench\TestCase;

class DatabaseSchemaDumpCommandTest extends TestCase
{
    public function testDumpCommandExitsWhenProhibited(): void
    {
        DumpCommand::prohibit();

        $this->artisan('schema:dump', ['--prune' => true])
            ->expectsOutputToContain('This command is prohibited from running in this environment.')
            ->assertFailed();
    }
}
