<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Workbench\WorkbenchServiceProvider;
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
            WorkbenchServiceProvider::class,
        ];
    }

    #[Test]
    public function itCanDropDatabaseUsingCommand(): void
    {
        $this->withSqliteDatabase(function (): void {
            $this->assertTrue(file_exists(database_path('database.sqlite')));

            $this->artisan('workbench:drop-sqlite-db')
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

            $this->artisan('workbench:drop-sqlite-db')
                ->expectsOutputToContain('File [@hypervel/database/database.sqlite] doesn\'t exist')
                ->assertOk();
        });
    }
}
