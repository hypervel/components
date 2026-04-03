<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Carbon\Carbon;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\after_resolving;
use function Hypervel\Testbench\default_migration_path;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('database.default', 'testing')]
class MigrateWithHypervelTest extends TestCase
{
    #[Test]
    #[DefineDatabase('loadApplicationMigrations')]
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

    #[Test]
    #[DefineDatabase('runApplicationMigrations')]
    public function itRunsTheMigrations(): void
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

    public function loadApplicationMigrations(): void
    {
        $this->loadHypervelMigrations(['--database' => 'testing']);
    }

    public function runApplicationMigrations(): void
    {
        after_resolving($this->app, 'migrator', function ($migrator): void {
            $migrator->path(default_migration_path());
        });

        $this->runHypervelMigrations(['--database' => 'testing']);
    }
}
