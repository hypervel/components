<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\BusServiceProvider;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Testbench\TestCase;

class BusServiceProviderTest extends TestCase
{
    public function testReloadConfigurationForgetsBothBatchRepositoryBindings(): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $databaseRepository = $this->app->make(DatabaseBatchRepository::class);

        $this->assertSame($repository, $databaseRepository);

        $this->app->getProvider(BusServiceProvider::class)->reloadConfiguration();

        $refreshedRepository = $this->app->make(BatchRepository::class);
        $refreshedDatabaseRepository = $this->app->make(DatabaseBatchRepository::class);

        $this->assertNotSame($repository, $refreshedRepository);
        $this->assertNotSame($databaseRepository, $refreshedDatabaseRepository);
        $this->assertSame($refreshedRepository, $refreshedDatabaseRepository);
    }
}
