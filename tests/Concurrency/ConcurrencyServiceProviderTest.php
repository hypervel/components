<?php

declare(strict_types=1);

namespace Hypervel\Tests\Concurrency;

use Hypervel\Concurrency\ConcurrencyManager;
use Hypervel\Concurrency\ConcurrencyServiceProvider;
use Hypervel\Concurrency\CoroutineDriver;
use Hypervel\Concurrency\SyncDriver;
use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;

class ConcurrencyServiceProviderTest extends TestCase
{
    public function testReloadConfigurationRebuildsResolvedDriversFromCurrentConfiguration(): void
    {
        $application = new Application;
        $config = new Repository([
            'concurrency' => [
                'default' => 'sync',
            ],
        ]);
        $application->instance('config', $config);
        $provider = new ConcurrencyServiceProvider($application);
        $provider->register();

        $manager = $application->make(ConcurrencyManager::class);
        $driver = $manager->driver();
        $this->assertInstanceOf(SyncDriver::class, $driver);

        $config->set('concurrency.default', 'coroutine');
        $provider->reloadConfiguration();

        $this->assertSame($manager, $application->make(ConcurrencyManager::class));
        $this->assertNotSame($driver, $manager->driver());
        $this->assertInstanceOf(CoroutineDriver::class, $manager->driver());
    }
}
