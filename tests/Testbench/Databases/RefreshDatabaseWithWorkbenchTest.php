<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;

#[ResetRefreshDatabaseState]
#[WithConfig('database.default', 'testing')]
class RefreshDatabaseWithWorkbenchTest extends TestCase
{
    use RefreshDatabase;
    use WithWorkbench {
        setUpWithWorkbench as baseSetUpWithWorkbench;
    }

    private int $workbenchSetups = 0;

    /**
     * Track Workbench setup while retaining its migration registration.
     */
    protected function setUpWithWorkbench(): void
    {
        ++$this->workbenchSetups;

        $this->baseSetUpWithWorkbench();
    }

    public function testWorkbenchRegistersDefaultMigrationsBeforeRefreshingTheDatabase(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(1, $this->workbenchSetups);
    }
}
