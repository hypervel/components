<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Support\Manager;
use Hypervel\Tests\TestCase;

class ManagerTest extends TestCase
{
    public function testDriverResolvesEveryEnumIdentifierRepresentation(): void
    {
        $manager = $this->createManager();

        $manager->extend('Primary', fn (): string => 'unit');
        $manager->extend('primary', fn (): string => 'string');
        $manager->extend('1', fn (): string => 'integer');
        $manager->extend('0', fn (): string => 'zero');

        $this->assertSame('unit', $manager->driver(ManagerUnitIdentifier::Primary));
        $this->assertSame('string', $manager->driver(ManagerStringIdentifier::Primary));
        $this->assertSame('integer', $manager->driver(ManagerIntegerIdentifier::Primary));
        $this->assertSame('zero', $manager->driver(ManagerIntegerIdentifier::Zero));
    }

    public function testNullAndEmptyStringSelectTheDefaultDriver(): void
    {
        $manager = $this->createManager();

        $manager->extend('default', fn (): string => 'default');
        $manager->extend('0', fn (): string => 'zero');

        $this->assertSame('default', $manager->driver());
        $this->assertSame('default', $manager->driver(''));
        $this->assertSame('zero', $manager->driver(ManagerIntegerIdentifier::Zero));
    }

    public function testSetContainerRefreshesTheConfigurationRepository(): void
    {
        $manager = $this->createManager();
        $container = new Container;
        $configuration = new Repository(['source' => 'replacement']);
        $container->instance('config', $configuration);

        $manager->setContainer($container);

        $this->assertSame($container, $manager->getContainer());
        $this->assertSame($configuration, $manager->getConfigurationRepository());
    }

    protected function createManager(): EnumIdentifierManager
    {
        $container = new Container;
        $container->instance('config', new Repository);

        return new EnumIdentifierManager($container);
    }
}

class EnumIdentifierManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'default';
    }

    public function getConfigurationRepository(): Repository
    {
        return $this->config;
    }
}

enum ManagerUnitIdentifier
{
    case Primary;
}

enum ManagerStringIdentifier: string
{
    case Primary = 'primary';
}

enum ManagerIntegerIdentifier: int
{
    case Primary = 1;
    case Zero = 0;
}
