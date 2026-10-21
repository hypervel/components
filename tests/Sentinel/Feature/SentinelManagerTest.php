<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentinel\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Sentinel\Drivers\Driver;
use Hypervel\Sentinel\Drivers\Hypervel;
use Hypervel\Sentinel\SentinelManager;
use Hypervel\Testbench\TestCase;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class SentinelManagerTest extends TestCase
{
    public function testItCanBeResolved(): void
    {
        $manager = app(SentinelManager::class);

        $this->assertInstanceOf(SentinelManager::class, $manager);
        $this->assertSame('hypervel', $manager->getDefaultDriver());

        tap($manager->driver(), function (Driver $driver) use ($manager): void {
            $this->assertInstanceOf(Hypervel::class, $driver);
            $this->assertInstanceOf(Driver::class, $driver);

            $this->assertSame($driver, $manager->driver('hypervel'));
        });
    }

    public function testItCanFallbackToDefaultDriver(): void
    {
        $manager = app(SentinelManager::class);

        $this->assertInstanceOf(Hypervel::class, $manager->driverOrFallback('foobar'));
        $this->assertSame($manager->driver(), $manager->driverOrFallback(null));
    }

    public function testItResolvesRegisteredDriversWithoutTheDefault(): void
    {
        $manager = app(SentinelManager::class);
        $driver = new Hypervel(fn (): Application => $this->app);

        $manager->extend('testing', fn (): Driver => $driver);

        $this->assertSame($driver, $manager->driverOrFallback('testing'));
        $this->assertArrayNotHasKey('hypervel', $manager->getDrivers());
    }

    public function testItDoesNotFallbackWhenARegisteredDriverFails(): void
    {
        $exception = new RuntimeException('Unable to create the driver.');
        $manager = app(SentinelManager::class);

        $manager->extend('failing', fn (): Driver => throw $exception);

        try {
            $manager->driverOrFallback('failing');

            $this->fail('The driver failure was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    public function testManagerIsSharedAcrossCoroutines(): void
    {
        // Drivers registered with extend() during boot must reach every request.
        $manager = app(SentinelManager::class);

        [$resolved] = parallel([fn (): SentinelManager => app(SentinelManager::class)]);

        $this->assertSame($manager, $resolved);
    }
}
