<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Testing\ParallelTesting;
use Symfony\Component\Process\Process;

class SchemaStateTest extends PostgresTestCase
{
    public function testLoadReportsStatementErrorsWithoutStoppingSupportedReloads(): void
    {
        $connection = DB::connection();
        $files = $this->app->make(Filesystem::class);
        $directory = ParallelTesting::tempDir('PostgresSchemaState');
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(static fn () => $files->deleteDirectory($directory));

        $connection->statement('CREATE SCHEMA schema_state_diagnostics');

        try {
            $path = $directory . '/schema.sql';
            $files->put($path, <<<'SQL'
                CREATE SCHEMA schema_state_diagnostics;
                CREATE TABLE schema_state_diagnostics.loaded (id integer);
                SELECT 'successful output';
                SQL);
            $output = [];

            $connection->getSchemaState(processFactory: static fn (string $command): Process => Process::fromShellCommandline($command)->setTimeout(20))
                ->handleOutputUsing(static function (string $type, string $buffer) use (&$output): void {
                    $output[$type] = ($output[$type] ?? '') . $buffer;
                })
                ->load($path);

            $this->assertTrue($connection->getSchemaBuilder()->hasTable('schema_state_diagnostics.loaded'));
            $this->assertStringContainsString('schema "schema_state_diagnostics" already exists', $output[Process::ERR] ?? '');
            $this->assertArrayNotHasKey(Process::OUT, $output);
        } finally {
            $connection->statement('DROP SCHEMA schema_state_diagnostics CASCADE');
        }
    }
}
