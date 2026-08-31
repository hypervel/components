<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

#[ResetRefreshDatabaseState]
class RefreshDatabaseWithDatabaseTruncationTest extends TestCase
{
    use DatabaseTruncation;
    use RefreshDatabase;

    protected array $connectionsToTransact = ['transactional'];

    protected array $connectionsToTruncate = ['truncated'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        CombinedDatabaseResetMigrationCounter::$runs = 0;
    }

    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');
        $config->set('database.default', 'transactional');
        $config->set('database.connections.transactional', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);
        $config->set('database.connections.truncated', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            workbench_path('database/migrations'),
            dirname(__DIR__) . '/Fixtures/database/migrations',
        ]);
    }

    #[Test]
    public function itRetainsBothInMemorySchemasAndTheirPdos(): void
    {
        $this->assertTrue(Schema::connection('transactional')->hasTable('testbench_users'));
        $this->assertTrue(Schema::connection('truncated')->hasTable('truncated_records'));
        $this->assertSame(1, CombinedDatabaseResetMigrationCounter::$runs);
        $this->assertArrayHasKey('transactional', RefreshDatabaseState::$inMemoryConnections);
        $this->assertArrayHasKey('truncated', RefreshDatabaseState::$inMemoryConnections);

        DB::connection('transactional')->table('testbench_users')->insert([
            'email' => 'second@example.com',
            'password' => 'password',
        ]);
        DB::connection('truncated')->table('truncated_records')->insert([
            'value' => 'first test',
        ]);

        $this->assertSame(2, DB::connection('transactional')->table('testbench_users')->count());
        $this->assertSame(1, DB::connection('truncated')->table('truncated_records')->count());
    }

    #[Test]
    #[Depends('itRetainsBothInMemorySchemasAndTheirPdos')]
    public function itRollsBackAndTruncatesWithoutRemigrating(): void
    {
        $this->assertTrue(Schema::connection('transactional')->hasTable('testbench_users'));
        $this->assertTrue(Schema::connection('truncated')->hasTable('truncated_records'));
        $this->assertSame(1, CombinedDatabaseResetMigrationCounter::$runs);
        $this->assertSame(1, DB::connection('transactional')->table('testbench_users')->count());
        $this->assertSame(0, DB::connection('truncated')->table('truncated_records')->count());
    }

    public static function tearDownAfterClass(): void
    {
        try {
            parent::tearDownAfterClass();
        } finally {
            CombinedDatabaseResetMigrationCounter::$runs = 0;
        }
    }
}

final class CombinedDatabaseResetMigrationCounter
{
    public static int $runs = 0;
}
