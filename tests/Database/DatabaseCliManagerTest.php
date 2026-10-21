<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\DatabaseCliConfiguration;
use Hypervel\Database\DatabaseCliManager;
use Hypervel\Tests\TestCase;

class DatabaseCliManagerTest extends TestCase
{
    public function testUnregisteredDriversHaveNoCustomConfiguration(): void
    {
        $manager = new DatabaseCliManager;

        $this->assertNull($manager->resolve(['driver' => 'custom']));
        $this->assertNull($manager->resolve(['driver' => 'mysql']));
    }

    public function testResolverReceivesTheConnectionAndRunsOnce(): void
    {
        $manager = new DatabaseCliManager;
        $connection = ['driver' => 'custom', 'database' => 'analytics'];
        $configuration = new DatabaseCliConfiguration(
            'custom-client',
            ['--database', 'analytics'],
            ['CLIENT_PASSWORD' => 'secret', 'INHERITED_OPTION' => false],
        );
        $calls = 0;

        $manager->extend('custom', function (array $resolved) use ($connection, $configuration, &$calls): DatabaseCliConfiguration {
            ++$calls;
            $this->assertSame($connection, $resolved);

            return $configuration;
        });

        $this->assertSame($configuration, $manager->resolve($connection));
        $this->assertSame(1, $calls);
        $this->assertSame('custom-client', $configuration->command);
        $this->assertSame(['--database', 'analytics'], $configuration->arguments);
        $this->assertSame(['CLIENT_PASSWORD' => 'secret', 'INHERITED_OPTION' => false], $configuration->environment);
    }

    public function testCallableObjectsCanResolveBuiltInDrivers(): void
    {
        $manager = new DatabaseCliManager;
        $manager->extend('mysql', new class {
            /**
             * Build the replacement client configuration.
             */
            public function __invoke(array $connection): DatabaseCliConfiguration
            {
                return new DatabaseCliConfiguration('custom-mysql', [$connection['database']]);
            }
        });

        $configuration = $manager->resolve(['driver' => 'mysql', 'database' => 'app']);

        $this->assertNotNull($configuration);
        $this->assertSame('custom-mysql', $configuration->command);
        $this->assertSame(['app'], $configuration->arguments);
        $this->assertSame([], $configuration->environment);
    }

    public function testRegisteringAgainReplacesTheResolver(): void
    {
        $manager = new DatabaseCliManager;
        $manager->extend('custom', static fn (array $connection): DatabaseCliConfiguration => new DatabaseCliConfiguration('first'));
        $manager->extend('custom', static fn (array $connection): DatabaseCliConfiguration => new DatabaseCliConfiguration('second'));

        $configuration = $manager->resolve(['driver' => 'custom']);

        $this->assertNotNull($configuration);
        $this->assertSame('second', $configuration->command);
        $this->assertSame([], $configuration->arguments);
        $this->assertSame([], $configuration->environment);
    }
}
