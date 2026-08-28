<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Hypervel\Tests\TestCase;

class InteractsWithMigrationsTest extends TestCase
{
    public function testRefreshDatabaseInMemoryStateIsDetectedAlongsideFileBackedTruncation(): void
    {
        $app = new Application;
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'memory',
                'connections' => [
                    'memory' => ['driver' => 'sqlite', 'database' => ':memory:'],
                    'file' => ['driver' => 'sqlite', 'database' => '/tmp/database.sqlite'],
                ],
            ],
        ]));

        $testCase = new CombinedMigrationStateTestCaseFixture('testPlaceholder');
        $testCase->setApplicationForTest($app);

        $this->assertTrue($testCase->usesInMemoryMigrationState());
    }
}

class CombinedMigrationStateTestCaseFixture extends TestbenchTestCase
{
    use DatabaseTruncation;
    use RefreshDatabase;

    protected array $connectionsToTransact = ['memory'];

    protected array $connectionsToTruncate = ['file'];

    public function testPlaceholder(): void
    {
    }

    public function setApplicationForTest(ApplicationContract $app): void
    {
        $this->app = $app;
    }

    public function usesInMemoryMigrationState(): bool
    {
        return $this->usesInMemoryDatabaseForMigrationState();
    }
}
