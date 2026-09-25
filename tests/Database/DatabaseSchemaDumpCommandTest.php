<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Console\DumpCommand;
use Hypervel\Testbench\TestCase;

class DatabaseSchemaDumpCommandTest extends TestCase
{
    public function testDumpCommandExitsWhenProhibited(): void
    {
        $path = database_path('schema/prohibited-dump.sql');

        $this->beforeApplicationDestroyed(fn () => $this->app->make('files')->delete($path));

        $this->assertDirectoryExists(database_path('migrations'));

        DumpCommand::prohibit();

        $this->artisan('schema:dump', ['--path' => $path, '--prune' => true])
            ->expectsOutputToContain('This command is prohibited from running in this environment.')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryExists(database_path('migrations'));
    }
}
