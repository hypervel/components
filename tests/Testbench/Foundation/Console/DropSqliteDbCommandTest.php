<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;

#[RequiresOperatingSystem('Linux|Darwin')]
class DropSqliteDbCommandTest extends TestCase
{
    use InteractsWithSqliteDatabaseFile;

    #[Override]
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TestbenchServiceProvider::class,
        ];
    }

    #[Test]
    public function itCanDropDatabaseUsingCommand(): void
    {
        $this->withSqliteDatabase(function (): void {
            $this->assertTrue(file_exists(database_path('database.sqlite')));

            $this->artisan('package:drop-sqlite-db')
                ->expectsOutputToContain('File [@hypervel/database/database.sqlite] has been deleted')
                ->assertOk();

            $this->assertFalse(file_exists(database_path('database.sqlite')));
        });
    }

    #[Test]
    public function itCannotDropDatabaseUsingCommandWhenDatabaseDoesntExists(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $this->assertFalse(file_exists(database_path('database.sqlite')));

            $this->artisan('package:drop-sqlite-db')
                ->expectsOutputToContain('File [@hypervel/database/database.sqlite] doesn\'t exists')
                ->assertOk();
        });
    }

    #[Test]
    public function itCanDropDatabaseNamedZero(): void
    {
        $filesystem = new Filesystem;
        $database = database_path('0.sqlite');
        $filesystem->put($database, '');

        try {
            $this->artisan('package:drop-sqlite-db', ['--database' => '0'])
                ->expectsOutputToContain('File [@hypervel/database/0.sqlite] has been deleted')
                ->assertOk();

            $this->assertFalse($filesystem->exists($database));
        } finally {
            $filesystem->delete($database);
        }
    }
}
