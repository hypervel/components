<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\SqliteDatabaseExists;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SqliteDatabaseExistsTest extends TestCase
{
    private string $basePath;

    /**
     * Create an isolated root for database files.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorSqlite');
        File::deleteDirectory($this->basePath);
        File::makeDirectory($this->basePath, 0775, true);
        $this->app->setBasePath($this->basePath);
    }

    /**
     * Remove the temporary databases.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->basePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testPassesWhenTheSqliteDatabaseFileExists(): void
    {
        $basePath = $this->basePath;
        mkdir($basePath . '/database');
        touch($basePath . '/database/database.sqlite');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => 'database/database.sqlite',
        ]);

        $result = (new SqliteDatabaseExists)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame($basePath . '/database/database.sqlite', $result->context['database']);
    }

    #[DataProvider('windowsPaths')]
    public function testPreservesAnAbsoluteWindowsSqliteDatabasePath(string $database): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $database,
        ]);

        $result = (new SqliteDatabaseExists)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame($database, $result->context['database']);
    }

    /**
     * Provide absolute Windows path variants.
     */
    public static function windowsPaths(): array
    {
        return [
            'backslash separators' => ['C:\Users\MyUser\Herd\my-project\database\database.sqlite'],
            'forward slash separators' => ['C:/Users/MyUser/Herd/my-project/database/database.sqlite'],
        ];
    }

    public function testReportsAMissingSqliteDatabaseFileAndCarriesThePathToTheFix(): void
    {
        $basePath = $this->basePath;

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => 'database/database.sqlite',
        ]);

        $diagnostic = new SqliteDatabaseExists;
        $result = $diagnostic->check();

        config(['database.connections.sqlite.database' => 'database/changed.sqlite']);

        $fix = $diagnostic->fix($result);

        $this->assertSame('fail', $result->status->value);
        $this->assertTrue($result->fixable);
        $this->assertSame($basePath . '/database/database.sqlite', $result->context['database']);
        $this->assertEquals([Link::docs('database', 'configuration')], $result->links);
        $this->assertSame('pass', $fix->status->value);
        $this->assertFileExists($basePath . '/database/database.sqlite');
        $this->assertFileDoesNotExist($basePath . '/database/changed.sqlite');
    }

    #[DataProvider('nonFileDatabases')]
    public function testDoesNotOfferToCreateMemoryDatabasesOrUris(string $database, string $code): void
    {
        config([
            'database.default' => 'analytics',
            'database.connections.analytics' => ['driver' => 'sqlite', 'database' => $database],
        ]);

        $result = (new SqliteDatabaseExists)->check();

        $this->assertSame('skip', $result->status->value);
        $this->assertSame('sqlite-database-exists.' . $code, $result->code);
        $this->assertFalse($result->fixable);
        $this->assertSame([], File::files($this->basePath));
    }

    /**
     * Provide SQLite memory names and connector-owned URI modes.
     */
    public static function nonFileDatabases(): array
    {
        return [
            [':memory:', 'not-file'],
            ['file::memory:?cache=shared', 'not-file'],
            ['file:analytics?mode=memory&cache=shared', 'not-file'],
            ['file:analytics.sqlite?mode=ro', 'uri'],
            ['file:analytics.sqlite?mode=rwc', 'uri'],
        ];
    }

    public function testUsesTheNormalizedDriverFromADatabaseUrl(): void
    {
        $database = $this->basePath . '/analytics.sqlite';
        touch($database);
        config([
            'database.default' => 'analytics',
            'database.connections.analytics' => ['driver' => 'mysql', 'url' => 'sqlite:///' . $database],
        ]);

        $result = (new SqliteDatabaseExists)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame($database, $result->context['database']);
    }
}
