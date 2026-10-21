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
class CreateSqliteDbCommandTest extends TestCase
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
    public function itCanGenerateDatabaseUsingCommand(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $this->assertFalse(file_exists(database_path('database.sqlite')));

            $this->artisan('workbench:create-sqlite-db')
                ->expectsOutputToContain('File [@hypervel/database/database.sqlite] generated')
                ->assertOk();

            $this->assertTrue(file_exists(database_path('database.sqlite')));
        });
    }

    #[Test]
    public function itCannotGenerateDatabaseUsingCommandWhenDatabaseAlreadyExists(): void
    {
        $this->withSqliteDatabase(function (): void {
            $this->assertTrue(file_exists(database_path('database.sqlite')));

            $this->artisan('workbench:create-sqlite-db')
                ->expectsOutputToContain('File [@hypervel/database/database.sqlite] already exists')
                ->assertOk();
        });
    }
}
