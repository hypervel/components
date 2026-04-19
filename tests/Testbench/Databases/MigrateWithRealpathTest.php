<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

#[WithConfig('database.default', 'testing')]
class MigrateWithRealpathTest extends TestCase
{
    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(realpath(workbench_path('database/migrations')));
    }

    #[Test]
    public function itRunsTheMigrations(): void
    {
        $users = DB::table('testbench_users')->where('id', '=', 1)->first();

        $this->assertEquals('crynobone@gmail.com', $users->email);
        $this->assertTrue(Hash::check('123', $users->password));
    }
}
