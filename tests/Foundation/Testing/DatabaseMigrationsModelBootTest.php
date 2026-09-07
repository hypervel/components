<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\DatabaseMigrationsModelBootTest;

use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Testbench\TestCase;

class DatabaseMigrationsModelBootTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        DatabaseMigrationsObserver::$creatingCalls = 0;
    }

    protected function afterRefreshingDatabase(): void
    {
        new DatabaseMigrationsObservedModel;
    }

    public function testModelBootedDuringDatabaseRefreshKeepsOneObserverRegistration(): void
    {
        (new DatabaseMigrationsObservedModel)->save();

        $this->assertSame(1, DatabaseMigrationsObserver::$creatingCalls);
    }
}

#[ObservedBy(DatabaseMigrationsObserver::class)]
class DatabaseMigrationsObservedModel extends Model
{
    public function save(array $options = []): bool
    {
        $this->fireModelEvent('creating');

        return true;
    }
}

class DatabaseMigrationsObserver
{
    public static int $creatingCalls = 0;

    public function creating(DatabaseMigrationsObservedModel $model): void
    {
        ++static::$creatingCalls;
    }
}
