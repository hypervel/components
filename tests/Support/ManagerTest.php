<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Support\Manager;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use stdClass;

class ManagerTest extends TestCase
{
    protected Container $container;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        $this->container->instance('config', new Repository);
    }

    public function testDefaultDriverCannotBeNull(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NullableManager($this->container))->driver();
    }

    public function testCustomDriverClosureBoundObjectIsManager(): void
    {
        $manager = new NullableManager($this->container);
        $manager->extend(__CLASS__, fn (): object => $this);
        $this->assertSame($manager, $manager->driver(__CLASS__));
    }

    public function testCustomDriverStaticClosure(): void
    {
        $manager = new NullableManager($this->container);
        $driver = new stdClass;

        $manager->extend(__CLASS__, static fn (): stdClass => $driver);
        $this->assertSame($driver, $manager->driver(__CLASS__));
    }

    public function testInvokableObjectDriverClosure(): void
    {
        $manager = new NullableManager($this->container);
        $driver = new stdClass;
        $creator = new CustomManagerDriver($driver);

        $manager->extend(__CLASS__, $creator(...));
        $this->assertSame($driver, $manager->driver(__CLASS__));
    }

    public function testEnumDriverCanBeResolved(): void
    {
        $manager = new NullableManager($this->container);
        $driver = new stdClass;

        $manager->extend('my_driver', static fn (): stdClass => $driver);
        $this->assertSame($driver, $manager->driver(ManagerDriverName::MyDriver));
    }

    public function testEnumDriverIsCached(): void
    {
        $manager = new NullableManager($this->container);

        $manager->extend('my_driver', static fn (): stdClass => new stdClass);

        $driver1 = $manager->driver(ManagerDriverName::MyDriver);
        $driver2 = $manager->driver(ManagerDriverName::MyDriver);

        $this->assertSame($driver1, $driver2);
    }

    public function testEnumDriverMatchesStringDriver(): void
    {
        $manager = new NullableManager($this->container);

        $manager->extend('my_driver', static fn (): stdClass => new stdClass);

        $fromEnum = $manager->driver(ManagerDriverName::MyDriver);
        $fromString = $manager->driver('my_driver');

        $this->assertSame($fromEnum, $fromString);
    }

    public function testUnitEnumDriverCanBeResolved(): void
    {
        $manager = new NullableManager($this->container);
        $driver = new stdClass;

        $manager->extend('MyDriver', static fn (): stdClass => $driver);
        $this->assertSame($driver, $manager->driver(ManagerUnitDriverName::MyDriver));
    }

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

    /**
     * Create a manager with a default driver.
     */
    protected function createManager(): EnumIdentifierManager
    {
        return new EnumIdentifierManager($this->container);
    }
}

class NullableManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): ?string
    {
        return null;
    }
}

class CustomManagerDriver
{
    /**
     * Create a custom driver factory.
     */
    public function __construct(private object $object)
    {
    }

    /**
     * Return the custom driver.
     */
    public function __invoke(): object
    {
        return $this->object;
    }
}

enum ManagerDriverName: string
{
    case MyDriver = 'my_driver';
}

enum ManagerUnitDriverName
{
    case MyDriver;
}

class EnumIdentifierManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return 'default';
    }

    /**
     * Get the configuration repository.
     */
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
