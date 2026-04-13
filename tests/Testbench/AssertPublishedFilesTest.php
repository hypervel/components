<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class AssertPublishedFilesTest extends TestCase
{
    use InteractsWithPublishedFiles;

    #[Test]
    public function itCanTestAssertFileContains(): void
    {
        $this->assertFileContains([
            'hypervel/hypervel',
        ], 'composer.json');

        $this->assertFileDoesNotContains([
            'orchestra/workbench',
        ], 'composer.json');

        $this->assertFileNotContains([
            'orchestra/workbench',
        ], 'composer.json');
    }

    #[Test]
    public function itCanTestAssertFileExists(): void
    {
        $this->assertFilenameExists('composer.json');

        $this->assertFilenameDoesNotExists('composer.lock');
        $this->assertFilenameNotExists('composer.lock');
    }

    #[Test]
    public function itCanTestAssertMigrationsFiles(): void
    {
        $this->assertMigrationFileContains([
            'return new class extends Migration',
            'Schema::create(\'users\', function (Blueprint $table) {',
        ], 'testbench_create_users_table.php', directory: 'migrations');

        $this->assertMigrationFileDoesNotContains([
            'class TestbenchCreateUsersTable extends Migration',
        ], 'testbench_create_users_table.php', directory: 'migrations');

        $this->assertMigrationFileExists('0001_01_01_000000_testbench_create_users_table.php', 'migrations');
        $this->assertMigrationFileDoesNotExists('0001_01_01_000000_create_users_table.php', 'migrations');
    }
}
