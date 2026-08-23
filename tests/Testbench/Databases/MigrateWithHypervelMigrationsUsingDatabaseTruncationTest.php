<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

#[WithConfig('database.default', 'testing')]
class MigrateWithHypervelMigrationsUsingDatabaseTruncationTest extends TestCase
{
    use DatabaseTruncation;
    use WithHypervelMigrations;

    private bool $truncated = false;

    public static function setUpBeforeClass(): void
    {
        ResetRefreshDatabaseState::run();

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        self::assertFalse(RefreshDatabaseState::$migrated);
        self::assertSame([], RefreshDatabaseState::$inMemoryConnections);
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }

    protected function afterTruncatingDatabase(): void
    {
        $this->truncated = true;
    }

    #[Test]
    #[DataProvider('applicationLifecycles')]
    public function itMigratesOnceAndThenTruncatesAcrossApplicationLifecycles(int $lifecycle): void
    {
        $this->assertSame([], $this->cachedTestMigratorProcessors);
        $this->assertTrue(RefreshDatabaseState::$migrated);
        $this->assertSame($lifecycle === 2, $this->truncated);
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('testbench_users'));
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame(
            0,
            DB::table('testbench_users')->where('email', 'truncation@example.com')->count(),
        );

        DB::table('testbench_users')->insert([
            'email' => 'truncation@example.com',
            'password' => (string) $lifecycle,
        ]);
    }

    public static function applicationLifecycles(): array
    {
        // The second case exercises database state retained by the first lifecycle.
        return [
            'first application' => [1],
            'second application' => [2],
        ];
    }
}
