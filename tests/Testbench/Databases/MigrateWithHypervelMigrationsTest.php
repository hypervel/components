<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('database.default', 'testing')]
#[WithConfig('database.connections.testing.pool.testing_enabled', true)]
class MigrateWithHypervelMigrationsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithHypervelMigrations;

    #[Test]
    public function itLoadsTheMigrations(): void
    {
        $now = CarbonImmutable::now();

        DB::table('users')->insert([
            'name' => 'Orchestra',
            'email' => 'crynobone@gmail.com',
            'password' => Hash::make('456'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $users = DB::table('users')->where('id', '=', 1)->first();

        $this->assertEquals('crynobone@gmail.com', $users->email);
        $this->assertTrue(Hash::check('456', $users->password));
    }

    #[Test]
    public function itStartsTheFirstUserTransactionInsideTheLazyTestTransaction(): void
    {
        $connection = DB::connection();

        $this->assertSame(0, $connection->transactionLevel());

        $connection->beginTransaction();

        $this->assertSame(2, $connection->transactionLevel());

        $connection->rollBack();

        $this->assertSame(1, $connection->transactionLevel());
    }
}
