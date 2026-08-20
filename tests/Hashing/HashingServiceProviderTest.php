<?php

declare(strict_types=1);

namespace Hypervel\Tests\Hashing;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Hashing\Argon2IdHasher;
use Hypervel\Hashing\ArgonHasher;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Hashing\HashingServiceProvider;
use Hypervel\Hashing\HashManager;
use Hypervel\Testbench\TestCase;

class HashingServiceProviderTest extends TestCase
{
    public function testShippedConfigurationResolvesAllBuiltInDrivers(): void
    {
        $manager = $this->app->make(HashManager::class);

        $this->assertInstanceOf(BcryptHasher::class, $manager->driver('bcrypt'));
        $this->assertInstanceOf(ArgonHasher::class, $manager->driver('argon'));
        $this->assertInstanceOf(Argon2IdHasher::class, $manager->driver('argon2id'));
    }

    public function testReloadConfigurationRebuildsResolvedDriversFromCurrentConfiguration(): void
    {
        $application = new Application;
        $config = new Repository([
            'hashing' => [
                'driver' => 'bcrypt',
                'bcrypt' => [
                    'rounds' => 12,
                    'verify' => true,
                    'limit' => null,
                ],
                'argon' => [
                    'memory' => 65536,
                    'threads' => 1,
                    'time' => 4,
                    'verify' => true,
                ],
            ],
        ]);
        $application->instance('config', $config);
        $provider = new HashingServiceProvider($application);
        $provider->register();

        $manager = $application->make('hash');
        $driver = $application->make('hash.driver');
        $this->assertInstanceOf(BcryptHasher::class, $driver);

        $config->set('hashing.driver', 'argon2id');
        $provider->reloadConfiguration();

        $refreshedDriver = $application->make('hash.driver');
        $this->assertSame($manager, $application->make(HashManager::class));
        $this->assertNotSame($driver, $refreshedDriver);
        $this->assertInstanceOf(Argon2IdHasher::class, $refreshedDriver);
    }
}
