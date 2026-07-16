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
class CreateSqliteDbCommandTest extends TestCase
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
    public function itCanGenerateDatabaseUsingCommand(): void
    {
        $this->withoutSqliteDatabase(function (): void {
            $this->assertFalse(file_exists(database_path('database.sqlite')));

            $this->artisan('package:create-sqlite-db')
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

            $this->artisan('package:create-sqlite-db')
                ->expectsOutputToContain('File [@hypervel/database/database.sqlite] already exists')
                ->assertOk();
        });
    }

    #[Test]
    public function itCanGenerateDatabaseNamedZero(): void
    {
        $filesystem = new Filesystem;
        $database = database_path('0.sqlite');
        $filesystem->delete($database);

        try {
            $this->artisan('package:create-sqlite-db', ['--database' => '0'])
                ->expectsOutputToContain('File [@hypervel/database/0.sqlite] generated')
                ->assertOk();

            $this->assertTrue($filesystem->exists($database));
        } finally {
            $filesystem->delete($database);
        }
    }
}
