<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[ResetRefreshDatabaseState]
#[WithConfig('database.default', 'testing')]
class RefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;
    use WithHypervelMigrations;
    use WithWorkbench;

    #[Test]
    public function itRunsTheMigrations(): void
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

    #[Test]
    #[DefineDatabase('addAdditionalTableAtRuntime')]
    public function itCanModifyMigrationsAtRuntime(): void
    {
        $this->assertTrue(Schema::hasTable('testbench_users'));
        $this->assertTrue(Schema::hasTable('testbench_auths'));

        $this->assertEquals([
            'id',
            'email',
            'password',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('testbench_users'));

        $this->assertEquals([
            'id',
            'two_factor_secret',
        ], Schema::getColumnListing('testbench_auths'));
    }

    public function addAdditionalTableAtRuntime(): void
    {
        Schema::create('testbench_auths', function (Blueprint $table): void {
            $table->id();
            $table->text('two_factor_secret')->nullable();
        });

        $this->beforeApplicationDestroyed(function (): void {
            Schema::drop('testbench_auths');
        });
    }

    #[Test]
    public function itCanResetWithRefreshDatabaseOnRuntime(): void
    {
        $this->assertTrue(Schema::hasTable('testbench_users'));
        $this->assertFalse(Schema::hasTable('testbench_auths'));
    }
}
