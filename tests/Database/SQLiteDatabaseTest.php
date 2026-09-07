<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\SQLiteDatabase;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SQLiteDatabaseTest extends TestCase
{
    #[DataProvider('uriProvider')]
    public function testItClassifiesSQLiteUris(string $database, bool $expected): void
    {
        $this->assertSame($expected, SQLiteDatabase::isUri($database));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function uriProvider(): array
    {
        return [
            'literal memory' => [':memory:', false],
            'relative file URI' => ['file:database.sqlite', true],
            'absolute file URI' => ['file:/tmp/database.sqlite', true],
            'triple-slash file URI' => ['file:///tmp/database.sqlite', true],
            'query-bearing file URI' => ['file:database.sqlite?mode=rwc', true],
            'uppercase prefix is a filename' => ['FILE:database.sqlite', false],
        ];
    }

    #[DataProvider('memoryProvider')]
    public function testItClassifiesInMemoryDatabases(string $database, bool $expected): void
    {
        $this->assertSame($expected, SQLiteDatabase::isInMemory($database));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function memoryProvider(): array
    {
        return [
            'literal memory' => [':memory:', true],
            'memory URI path' => ['file::memory:', true],
            'memory URI path with shared cache' => ['file::memory:?cache=shared', true],
            'encoded memory URI path' => ['file:%3Amemory%3A', true],
            'empty URI path in memory mode' => ['file:?mode=memory', true],
            'named URI in memory mode' => ['file:database?mode=memory', true],
            'encoded memory mode' => ['file:database?mode=%6demory', true],
            'memory mode after another parameter' => ['file:database?cache=shared&mode=memory', true],
            'last duplicate mode wins for memory' => ['file:database?mode=rwc&mode=memory', true],
            'last duplicate mode wins for file' => ['file:database?mode=memory&mode=rwc', false],
            'ordinary path' => ['/tmp/database.sqlite', false],
            'ordinary file URI' => ['file:/tmp/database.sqlite', false],
            'query-bearing file URI' => ['file:/tmp/database.sqlite?mode=rwc', false],
            'uppercase URI prefix is a filename' => ['FILE:database?mode=memory', false],
            'uppercase mode key is ignored' => ['file:database?MODE=memory', false],
            'mixed-case mode value is not memory' => ['file:database?mode=MEMORY', false],
        ];
    }

    #[DataProvider('configurationProvider')]
    public function testItClassifiesInMemoryConnectionConfigurations(array $configuration, bool $expected): void
    {
        $this->assertSame($expected, SQLiteDatabase::isInMemoryConfiguration($configuration));
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function configurationProvider(): array
    {
        return [
            'discrete SQLite memory' => [['driver' => 'sqlite', 'database' => ':memory:'], true],
            'SQLite memory URL' => [['url' => 'sqlite:///:memory:'], true],
            'URL overrides discrete values' => [[
                'driver' => 'sqlite',
                'database' => ':memory:',
                'url' => 'mysql://root:secret@database/app',
            ], false],
            'non-SQLite memory name' => [['driver' => 'mysql', 'database' => ':memory:'], false],
            'incomplete configuration' => [['driver' => 'sqlite'], false],
        ];
    }
}
