<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Cache\Console\CacheTableCommand;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Date;

class CacheTableCommandTest extends TestCase
{
    public function testCreateMakesCollisionFreeMigrations(): void
    {
        Date::setTestNow('2026-07-23 12:00:00');

        try {
            $this->artisan(CacheTableCommand::class)->assertExitCode(0);

            $this->assertMigrationFileContains([
                'use Hypervel\Database\Migrations\Migration;',
                'return new class extends Migration',
                "Schema::create('cache', function (Blueprint \$table) {",
                "Schema::dropIfExists('cache');",
            ], 'create_cache_table.php');

            $this->assertMigrationFileContains([
                'use Hypervel\Database\Migrations\Migration;',
                'return new class extends Migration',
                "Schema::create('cache_locks', function (Blueprint \$table) {",
                "Schema::dropIfExists('cache_locks');",
            ], 'create_cache_locks_table.php');

            $files = $this->app->make(Filesystem::class)->glob(database_path('migrations/*.php'));

            $this->assertIsArray($files);
            $this->assertSame([
                '2026_07_23_120000_create_cache_table.php',
                '2026_07_23_120001_create_cache_locks_table.php',
            ], array_map(basename(...), $files));
        } finally {
            Date::setTestNow();
        }
    }
}
