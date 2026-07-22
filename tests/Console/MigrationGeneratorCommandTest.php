<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\MigrationGeneratorCommand;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class MigrationGeneratorCommandTest extends TestCase
{
    public function testGlobFailureIsReported(): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('glob')->once()->with('/app/database/migrations/*.php')->andReturnFalse();

        $command = new TestMigrationGeneratorCommand($files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read migration files matching [/app/database/migrations/*.php].');

        $command->matchingMigrationFilesForTest('/app/database/migrations/*.php');
    }
}

class TestMigrationGeneratorCommand extends MigrationGeneratorCommand
{
    /**
     * Get migration files matching the given pattern.
     *
     * @return list<string>
     */
    public function matchingMigrationFilesForTest(string $pattern): array
    {
        return $this->matchingMigrationFiles($pattern);
    }

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return 'test';
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return 'test.stub';
    }
}
