<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
#[ResetRefreshDatabaseState]
#[WithConfig('database.default', 'testing')]
class DatabaseMigrationsUsingEventsTest extends TestCase
{
    use DatabaseMigrations;
    use WithWorkbench;

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('testbench_staffs', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('password');

            $table->timestamps();
        });
    }

    #[Override]
    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('testbench_staffs');
    }

    #[Test]
    public function itCreateDatabaseMigrations(): void
    {
        $this->assertEquals([
            'id',
            'email',
            'password',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('testbench_staffs'));
    }
}
