<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\artisan;

#[WithConfig('database.default', 'testing')]
class MigrateDatabaseWithoutMockedConsoleTest extends TestCase
{
    use WithWorkbench;

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        artisan($this, 'migrate', ['--database' => 'testing']);
    }

    #[Test]
    public function itRunsTheMigrationsWithoutMockedConsole(): void
    {
        $users = DB::table('testbench_users')->where('id', '=', 1)->first();

        $this->assertEquals('crynobone@gmail.com', $users->email);
        $this->assertTrue(Hash::check('123', $users->password));

        $this->assertEquals([
            'id',
            'email',
            'password',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('testbench_users'));
    }
}
