<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\RefreshDatabaseModelBootTest;

use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;

class RefreshDatabaseModelBootTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RefreshDatabaseObserver::$creatingCalls = 0;
    }

    protected function afterRefreshingDatabase(): void
    {
        new RefreshDatabaseObservedModel;
    }

    public function testModelBootedDuringDatabaseRefreshKeepsOneObserverRegistration(): void
    {
        Event::fake(UnrelatedEvent::class);

        (new RefreshDatabaseObservedModel)->save();

        $this->assertSame(1, RefreshDatabaseObserver::$creatingCalls);
    }
}

#[ObservedBy(RefreshDatabaseObserver::class)]
class RefreshDatabaseObservedModel extends Model
{
    public function save(array $options = []): bool
    {
        $this->fireModelEvent('creating');

        return true;
    }
}

class RefreshDatabaseObserver
{
    public static int $creatingCalls = 0;

    public function creating(RefreshDatabaseObservedModel $model): void
    {
        ++static::$creatingCalls;
    }
}

class UnrelatedEvent
{
}
