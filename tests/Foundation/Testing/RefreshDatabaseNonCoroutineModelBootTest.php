<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\RefreshDatabaseNonCoroutineModelBootTest;

use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;

class RefreshDatabaseNonCoroutineModelBootTest extends TestCase
{
    use RefreshDatabase;

    protected bool $runTestsInCoroutine = false;

    protected function setUp(): void
    {
        parent::setUp();

        RefreshDatabaseNonCoroutineObserver::$creatingCalls = 0;
    }

    protected function afterRefreshingDatabase(): void
    {
        new RefreshDatabaseNonCoroutineObservedModel;
    }

    public function testModelBootedDuringDatabaseRefreshKeepsOneObserverRegistration(): void
    {
        (new RefreshDatabaseNonCoroutineObservedModel)->save();

        $this->assertSame(1, RefreshDatabaseNonCoroutineObserver::$creatingCalls);
    }
}

#[ObservedBy(RefreshDatabaseNonCoroutineObserver::class)]
class RefreshDatabaseNonCoroutineObservedModel extends Model
{
    public function save(array $options = []): bool
    {
        $this->fireModelEvent('creating');

        return true;
    }
}

class RefreshDatabaseNonCoroutineObserver
{
    public static int $creatingCalls = 0;

    public function creating(RefreshDatabaseNonCoroutineObservedModel $model): void
    {
        ++static::$creatingCalls;
    }
}
