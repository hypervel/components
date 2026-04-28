<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Carbon\Carbon;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('database.default', 'testing')]
class MigrateWithHypervelMigrationsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithHypervelMigrations;

    #[Test]
    public function itLoadsTheMigrations(): void
    {
        $now = Carbon::now();

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
}
